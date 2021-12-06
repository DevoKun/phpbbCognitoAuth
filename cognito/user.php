<?php
/**
 * *
 * AWS Cognito Authentication. An extension for the phpBB Forum Software package.
 *
 * @package     mrfg\cogauth\cognito
 * @subpackage	user
 *
 * @copyright (c) 2018, Mark Gawler
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * Date: 20/07/19
 */

namespace mrfg\cogauth\cognito;


use phpbb\auth\auth;
use phpbb\config\config;
use phpbb\db\driver\driver_interface;
use phpbb\passwords\manager;

class user
{
	/** @var \phpbb\user $user */
	protected $user;

	/** @var \phpbb\auth\auth $auth */
	protected $auth;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\config\config $config */
	protected $config;


	/** @var \phpbb\passwords\manager $passwords_manager */
	protected $passwords_manager;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\controller\helper */
	protected $helper;

	/** @var string $phpbb_root_path */
	protected $phpbb_root_path;

	/** @var string $php_ext */
	protected $php_ext;

	/** @var string $php_ext */
	protected $usermap;

	/**
	 * user constructor.
	 **
	 *
	 * @param \phpbb\user                       $user
	 * @param \phpbb\auth\auth                  $auth
	 * @param \phpbb\db\driver\driver_interface $db
	 * @param \phpbb\config\config              $config
	 * @param \phpbb\passwords\manager          $passwords_manager
	 * @param string                            $phpbb_root_path
	 * @param string                            $php_ext
	 * @param string                            $usermap
	 */
	public function __construct(
		\phpbb\user $user, auth $auth, driver_interface $db, config $config, manager $passwords_manager,
		string $phpbb_root_path, string $php_ext, string $usermap)
	{
		$this->user = $user;
		$this->auth = $auth;
		$this->db = $db;
		$this->config = $config;
		$this->passwords_manager = $passwords_manager;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;
		$this->usermap = $usermap;

	}

	/**
	 * @param int $user_id phpBB user id
	 *
	 * @return string cognito username
	 */
	public function get_cognito_username(int $user_id): string
	{
		return $this->get_cognito_usermap_attributes($user_id)['cognito_username'];
	}

	/**
	 * @param int $user_id phpBB user id
	 *
	 * @return array cognito username
	 */
	public function get_cognito_usermap_attributes(int $user_id): array
	{
		$sql = 'SELECT cognito_username,password_sync FROM ' . $this->usermap . '
				WHERE phpbb_user_id = ' . $this->db->sql_escape($user_id);
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if ($row)
		{
			return array(
				'cognito_username' => $row['cognito_username'],
				'phpbb_password_valid' => (bool)$row['password_sync']
			);
		} else {
			// Default to returning autogenerated username which is the case for phpbb created users.
			return array(
				'cognito_username' => 'u' . str_pad($user_id, 6, "0", STR_PAD_LEFT),
				'phpbb_password_valid' => true);
		}
	}

	protected function create_usermap_entry($user_id, $username, $password_sync = false)
	{
		$data = array(
			'phpbb_user_id' => (int) $user_id,
			'cognito_username' => $username,
			'password_sync' => $password_sync,
		);
		$sql = 'INSERT INTO ' . $this->usermap . $this->db->sql_build_array('INSERT', $data);
		$this->db->sql_query($sql);
	}

	/**
	 * @param int     $user_id
	 * @param boolean $valid
	 */
	public function set_phpbb_password_status(int $user_id, bool $valid)
	{
		$sql = 'UPDATE ' . $this->usermap . '
					SET password_sync = ' . $valid . '
					WHERE phpbb_user_id = ' . $this->db->sql_escape($user_id);
		$this->db->sql_query($sql);
	}

	/**
	 * Automatically login a user,
	 *
	 * @param validation_result $validation
	 *
	 * @return bool True is login success
	 */
	public function login(validation_result $validation): bool
	{
		if ($validation instanceof validation_result && !$validation->is_new_user())
		{
			$this->user->session_create($validation->phpbb_user_id, false, false, true);  //todo  remember me
			$this->auth->acl($this->user->data);
			$this->user->setup();
			return true;

		}
		return false;
	}

	public function get_phpbb_session_id(): string
	{
		return $this->user->session_id;
	}

	public function get_ip(): string
	{
		return $this->user->ip;
	}

	/**
	 * @param $user_attributes
	 * @return false|int # User ID
	 */
	public function add_user($user_attributes)
	{
		include_once($this->phpbb_root_path . 'includes/functions_user.' . $this->php_ext);
		// Which group by default?
		$group_name = 'REGISTERED';

		$sql = 'SELECT group_id
				FROM ' . GROUPS_TABLE . "
				WHERE group_name = '" . $this->db->sql_escape($group_name) . "'
					AND group_type = " . GROUP_SPECIAL;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row)
		{
			trigger_error('NO_GROUP');
		}

		$group_id = $row['group_id'];

		$user_type = USER_NORMAL;
		$user_inactive_reason = 0;
		$user_inactive_time = 0;

		$user_password = gen_rand_string_friendly(max(8, mt_rand((int) $this->config['min_pass_chars'], (int) $this->config['max_pass_chars'])));

		$user_row = array(
			'username'				=> $user_attributes['cognito:username'],
			'user_password'			=> $this->passwords_manager->hash($user_password),
			'user_email'			=> $user_attributes['email'],
			'group_id'				=> (int) $group_id,
			'user_timezone'			=> $this->config['board_timezone'],
			'user_lang'				=> $this->user->lang_name,
			'user_type'				=> $user_type,
			'user_actkey'			=> '',
			'user_ip'				=> $this->user->ip,
			'user_regdate'			=> time(),
			'user_inactive_reason'	=> $user_inactive_reason,
			'user_inactive_time'	=> $user_inactive_time,
		);

		if ($this->config['new_member_post_limit'])
		{
			$user_row['user_new'] = 1;
		}

		// Register user...
		$user_id = user_add($user_row);  	//phpBB register

		// Create a user entry to enable lookup of Cognito username from phpBB id
		$this->create_usermap_entry($user_id, $user_attributes['cognito:username']);
		return $user_id;
	}

	/**
	 * @param string $user_id
	 * @param string $password
	 * @return string
	 */
	public function update_phpbb_password(string $user_id, string $password): string
	{
		$hash = $this->passwords_manager->hash($password);

		// Update the password in the users table to the new format
		$sql = 'UPDATE ' . USERS_TABLE . "
					SET user_password = '" . $this->db->sql_escape($hash) . "'
					WHERE user_id = {$user_id}";
		$this->db->sql_query($sql);
		return $hash;
	}

	/**
	 * @param integer $user_id
	 */
	public function reset_phpbb_login_attempts(int $user_id)
	{
		$sql = 'DELETE FROM ' . LOGIN_ATTEMPT_TABLE . ' WHERE user_id = ' . $user_id;
		$this->db->sql_query($sql);

		$sql = 'UPDATE ' . USERS_TABLE . ' SET user_login_attempts = 0 WHERE user_id = ' . $user_id;
		$this->db->sql_query($sql);
	}
}
