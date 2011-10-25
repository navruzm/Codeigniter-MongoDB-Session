<?php
(defined('BASEPATH')) OR exit('No direct script access allowed');

/**
 * Mongodb driven MY_Session Class, based on CodeIgniter CI_Session class.
 */
class MY_Session extends CI_Session
{

    //@todo add config
    private $sess_use_mongo_database = TRUE;
    private $sess_collection_name = 'session';
    public $CI;

    /**
     * Session Constructor
     *
     * The constructor runs the session routines automatically
     * whenever the class is instantiated.
     */
    public function __construct($params = array())
    {

        $this->CI = & get_instance();
        $this->CI->load->library('mongo_db');
        parent::__construct();
    }

    // --------------------------------------------------------------------

    /**
     * Fetch the current session data if it exists
     *
     * @access	public
     * @return	bool
     */
    function sess_read()
    {
        // Fetch the cookie
        $session = $this->CI->input->cookie($this->sess_cookie_name);

        // No cookie? Goodbye cruel world!...
        if ($session === FALSE)
        {
            log_message('debug', 'A session cookie was not found.');
            return FALSE;
        }

        // Decrypt the cookie data
        if ($this->sess_encrypt_cookie == TRUE)
        {
            $session = $this->CI->encrypt->decode($session);
        }
        else
        {
            // encryption was not used, so we need to check the md5 hash
            $hash = substr($session, strlen($session) - 32); // get last 24 chars
            $session = substr($session, 0, strlen($session) - 32);

            // Does the md5 hash match? This is to prevent manipulation of session data in userspace
            if ($hash !== md5($session . $this->encryption_key))
            {
                log_message('error', 'The session cookie data did not match what was expected. This could be a possible hacking attempt.');
                $this->sess_destroy();
                return FALSE;
            }
        }

        // Unserialize the session array
        $session = $this->_unserialize($session);
        // Is the session data we unserialized an array with the correct format?
        if (!is_array($session) OR !isset($session['session_id']) OR !isset($session['ip_address']) OR !isset($session['user_agent']) OR !isset($session['last_activity']))
        {
            $this->sess_destroy();
            return FALSE;
        }

        // Is the session current?
        if (($session['last_activity'] + $this->sess_expiration) < $this->now)
        {
            $this->sess_destroy();
            return FALSE;
        }

        // Does the IP Match?
        if ($this->sess_match_ip == TRUE AND $session['ip_address'] != $this->CI->input->ip_address())
        {
            $this->sess_destroy();
            return FALSE;
        }

        // Does the User Agent Match?
        if ($this->sess_match_useragent == TRUE AND trim($session['user_agent']) != trim(substr($this->CI->input->user_agent(), 0, 120)))
        {
            $this->sess_destroy();
            return FALSE;
        }

        // Is there a corresponding session in the DB?
        if ($this->sess_use_mongo_database === TRUE)
        {
            $where = array('_id' => new MongoId($session['session_id']));

            if ($this->sess_match_ip == TRUE)
            {
                $where['ip_address'] = $session['ip_address'];
            }

            if ($this->sess_match_useragent == TRUE)
            {
                $where['user_agent'] = $session['user_agent'];
            }

            $query = $this->CI->mongo_db->{$this->sess_collection_name}->findOne($where);
            // No result? Kill it!
            if (count($query) == 0)
            {
                $this->sess_destroy();
                return FALSE;
            }

            // Is there custom data? If so, add it to the main session array
            if (isset($query['user_data']) AND $query['user_data'] != '')
            {
                $custom_data = $this->_unserialize($query['user_data']);

                if (is_array($custom_data))
                {
                    foreach ($custom_data as $key => $val)
                    {
                        $session[$key] = $val;
                    }
                }
            }
        }

        // Session is valid!
        $this->userdata = $session;
        unset($session);

        return TRUE;
    }

    // --------------------------------------------------------------------

    /**
     * Write the session data
     *
     * @access	public
     * @return	void
     */
    function sess_write()
    {
        // Are we saving custom data to the DB? If not, all we do is update the cookie
        if ($this->sess_use_mongo_database === FALSE)
        {
            $this->_set_cookie();
            return;
        }

        // set the custom userdata, the session data we will set in a second
        $custom_userdata = $this->userdata;
        $cookie_userdata = array();

        // Before continuing, we need to determine if there is any custom data to deal with.
        // Let's determine this by removing the default indexes to see if there's anything left in the array
        // and set the session data while we're at it
        foreach (array('session_id', 'ip_address', 'user_agent', 'last_activity') as $val)
        {
            unset($custom_userdata[$val]);
            $cookie_userdata[$val] = $this->userdata[$val];
        }

        // Did we find any custom data? If not, we turn the empty array into a string
        // since there's no reason to serialize and store an empty array in the DB
        if (count($custom_userdata) === 0)
        {
            $custom_userdata = '';
        }
        else
        {
            // Serialize the custom data array so we can store it
            $custom_userdata = $this->_serialize($custom_userdata);
        }

        // Run the update query

        $this->CI->mongo_db->{$this->sess_collection_name}
                ->update(array('_id' => new MongoId($this->userdata['session_id'])), array('$set' => array('last_activity' => $this->userdata['last_activity'], 'user_data' => $custom_userdata)));

        // Write the cookie. Notice that we manually pass the cookie data array to the
        // _set_cookie() function. Normally that function will store $this->userdata, but
        // in this case that array contains custom data, which we do not want in the cookie.
        $this->_set_cookie($cookie_userdata);
    }

    // --------------------------------------------------------------------

    /**
     * Create a new session
     *
     * @access	public
     * @return	void
     */
    function sess_create()
    {
        $sessid = '';
        while (strlen($sessid) < 24)
        {
            $sessid .= mt_rand(0, mt_getrandmax());
        }

        // To make the session ID even more secure we'll combine it with the user's IP
        $sessid .= $this->CI->input->ip_address();

        $this->userdata = array(
            'session_id' => substr(md5(uniqid($sessid, TRUE)), 0, 24),
            'ip_address' => $this->CI->input->ip_address(),
            'user_agent' => substr($this->CI->input->user_agent(), 0, 120),
            'last_activity' => $this->now
        );


        // Save the data to the DB if needed
        if ($this->sess_use_mongo_database === TRUE)
        {
            $_userdata = $this->userdata;
            $_userdata['_id'] = new MongoId($_userdata['session_id']);
            unset($_userdata['session_id']);
            $this->CI->mongo_db->{$this->sess_collection_name}->insert($_userdata);
            unset($_userdata);
        }

        // Write the cookie
        $this->_set_cookie();
    }

    // --------------------------------------------------------------------

    /**
     * Update an existing session
     *
     * @access	public
     * @return	void
     */
    function sess_update()
    {
        // We only update the session every five minutes by default
        if (($this->userdata['last_activity'] + $this->sess_time_to_update) >= $this->now)
        {
            return;
        }

        // Save the old session id so we know which record to
        // update in the database if we need it
        $old_sessid = $this->userdata['session_id'];
        $new_sessid = '';
        while (strlen($new_sessid) < 24)
        {
            $new_sessid .= mt_rand(0, mt_getrandmax());
        }

        // To make the session ID even more secure we'll combine it with the user's IP
        $new_sessid .= $this->CI->input->ip_address();

        // Turn it into a hash
        $new_sessid = substr(md5(uniqid($new_sessid, TRUE)), 0, 24);

        // Update the session data in the session data array
        $this->userdata['session_id'] = $new_sessid;
        $this->userdata['last_activity'] = $this->now;

        // _set_cookie() will handle this for us if we aren't using database sessions
        // by pushing all userdata to the cookie.
        $cookie_data = NULL;

        // Update the session ID and last_activity field in the DB if needed
        if ($this->sess_use_mongo_database === TRUE)
        {
            // set cookie explicitly to only have our session data
            $cookie_data = array();
            foreach (array('session_id', 'ip_address', 'user_agent', 'last_activity') as $val)
            {
                $cookie_data[$val] = $this->userdata[$val];
            }

            $current = $this->CI->mongo_db->{$this->sess_collection_name}->findOne(array('_id' => new MongoId($old_sessid)));
            $current['_id'] = new MongoId($new_sessid);
            $this->CI->mongo_db->{$this->sess_collection_name}
                    ->remove(array('_id' => new MongoId($old_sessid)));
            $this->CI->mongo_db->{$this->sess_collection_name}
                    ->insert($current);
        }

        // Write the cookie
        $this->_set_cookie($cookie_data);
    }

    // --------------------------------------------------------------------

    /**
     * Destroy the current session
     *
     * @access	public
     * @return	void
     */
    function sess_destroy()
    {
        // Kill the session DB row
        if ($this->sess_use_mongo_database === TRUE AND isset($this->userdata['session_id']))
        {
            $this->CI->mongo_db->{$this->sess_collection_name}
                    ->remove(array('_id' => new MongoId($this->userdata['session_id'])));
        }

        // Kill the cookie
        setcookie(
                $this->sess_cookie_name, addslashes(serialize(array())), ($this->now - 31500000), $this->cookie_path, $this->cookie_domain, 0
        );
    }

    // --------------------------------------------------------------------

    /**
     * Garbage collection
     *
     * This deletes expired session rows from database
     * if the probability percentage is met
     *
     * @access	public
     * @return	void
     */
    function _sess_gc()
    {
        if ($this->sess_use_mongo_database != TRUE)
        {
            return;
        }

        srand(time());
        if ((rand() % 100) < $this->gc_probability)
        {
            $expire = $this->now - $this->sess_expiration;

            $this->CI->mongo_db->{$this->sess_collection_name}
                    ->remove(array('last_activity' => array('$lt' => $expire)));
            log_message('debug', 'Session garbage collection performed.');
        }
    }

    function keep_all_flashdata()
    {
        foreach ($this->CI->session->all_userdata() as $key => $value)
        {
            if (strpos($key, 'flash:old:') !== FALSE)
            {
                $key = str_replace('flash:old:', '', $key);
                $this->CI->session->keep_flashdata($key);
            }
        }
    }

}

// END Session Class

/* End of file MY_Session.php */
/* Location: ./application/libraries/MY_Session.php */