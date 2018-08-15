<?php
/**
 * Created by PhpStorm.
 * User: mrfg
 * Date: 03/08/18
 * Time: 18:26
 */

namespace mrfg\cogauth\auth\provider;

use Aws\CognitoIdentityProvider\Exception\CognitoIdentityProviderException;
use phpbb\debug\error_handler;
use phpbb\install\module\install_database\task\add_default_data;

//define('',);

define('COG_LOGIN_SUCCESS', 1);
define('COG_LOGIN_NO_AUTH', 2);
define('COG_LOGIN_NO_USER', 3);
define('COG_LOGIN_ERROR_PASSWORD',4);

define('COG_MIGRATE_SUCCESS ',10);

class cogauth extends \phpbb\auth\provider\base
{
	/**
	 * phpBB passwords manager
	 *
	 * @var \phpbb\passwords\manager
	 */
	protected $passwords_manager;

	/**
	 * DI container
	 *
	 * @var \Symfony\Component\DependencyInjection\ContainerInterface
	 */
	protected $phpbb_container;

	/**
	 * @var \phpbb\config\config $config Config object
	 */
	protected $config;

	/**
	 * @var \phpbb\request\request $request Request object
	 */
	protected $request;

	/**
	 * @var \phpbb\user
	 */
	protected $user;

	/**
	 * @var \phpbb\
	 */
	protected $php_ext;

	/**
	 * @var \phpbb\
	 */
	protected $phpbb_root_path;

	/**
	 * @var \Aws\Sdk
	 */
	protected $aws;

	/**
	 * @var  \Aws\CognitoIdentityProvider\CognitoIdentityProviderClient
	 */
	protected $client;

	/**
	 * @var $String
	 */
	protected $user_pool_id;

	/**
	 * @var $string
	 */
	protected $client_id;

	/**
	 * @var String
	 */
	protected $client_secret;

	/**
	 * @var \phpbb\db\driver\driver_interface
	 */
	protected $db;





	/**
	 * Database Authentication Constructor
	 *
	 * @param	\phpbb\db\driver\driver_interface		$db
	 * @param	\phpbb\config\config 		$config
	 * @param	\phpbb\passwords\manager	$passwords_manager
	 * @param	\phpbb\request\request		$request
	 * @param	\phpbb\user			$user
	 * @param	\Symfony\Component\DependencyInjection\ContainerInterface $phpbb_container DI container
	 * @param	string				$phpbb_root_path
	 * @param	string				$php_ext
	 */
	public function __construct(\phpbb\db\driver\driver_interface $db, \phpbb\config\config $config, \phpbb\passwords\manager $passwords_manager, \phpbb\request\request $request, \phpbb\user $user, \Symfony\Component\DependencyInjection\ContainerInterface $phpbb_container, $phpbb_root_path, $php_ext)
	{
		$this->db = $db;
		$this->config = $config;
		$this->passwords_manager = $passwords_manager;
		$this->request = $request;
		$this->user = $user;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;
		$this->phpbb_container = $phpbb_container;

		$this->user_pool_id = $config['cogauth_pool_id'];
		$this->client_id = $config['cogauth_client_id'];
		$this->client_secret = $config['cogauth_client_secret'];

		$this->aws = new \Aws\Sdk(
			array(
				'credentials' => array(
				'key' => $config['cogauth_aws_key'],
				'secret' => $config['cogauth_aws_secret'],
			),
			'version' => '2016-04-18',
			'region' => $config['cogauth_aws_region'],
			)
		);
		$this->client = $this->aws->createCognitoIdentityProvider();

	}

	/**
	 * {@inheritdoc}
	 */
	public function login_old($username, $password)
	{
		// Auth plugins get the password untrimmed.
		// For compatibility we trim() here.
		$password = trim($password);

		// do not allow empty password
		if (!$password)
		{
			return array(
				'status'    => LOGIN_ERROR_PASSWORD,
				'error_msg' => 'NO_PASSWORD_SUPPLIED',
				'user_row'  => array('user_id' => ANONYMOUS),
			);
		}

		if (!$username)
		{
			return array(
				'status'    => LOGIN_ERROR_USERNAME,
				'error_msg' => 'LOGIN_ERROR_USERNAME',
				'user_row'  => array('user_id' => ANONYMOUS),
			);
		}

		$username_clean = utf8_clean_string($username);

		$sql = 'SELECT *
			FROM ' . USERS_TABLE . "
			WHERE username_clean = '" . $this->db->sql_escape($username_clean) . "'";
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (($this->user->ip && !$this->config['ip_login_limit_use_forwarded']) ||
			($this->user->forwarded_for && $this->config['ip_login_limit_use_forwarded']))
		{
			$sql = 'SELECT COUNT(*) AS attempts
				FROM ' . LOGIN_ATTEMPT_TABLE . '
				WHERE attempt_time > ' . (time() - (int) $this->config['ip_login_limit_time']);
			if ($this->config['ip_login_limit_use_forwarded'])
			{
				$sql .= " AND attempt_forwarded_for = '" . $this->db->sql_escape($this->user->forwarded_for) . "'";
			}
			else
			{
				$sql .= " AND attempt_ip = '" . $this->db->sql_escape($this->user->ip) . "' ";
			}

			$result = $this->db->sql_query($sql);
			$attempts = (int) $this->db->sql_fetchfield('attempts');
			$this->db->sql_freeresult($result);

			$attempt_data = array(
				'attempt_ip'            => $this->user->ip,
				'attempt_browser'       => trim(substr($this->user->browser, 0, 149)),
				'attempt_forwarded_for' => $this->user->forwarded_for,
				'attempt_time'          => time(),
				'user_id'               => ($row) ? (int) $row['user_id'] : 0,
				'username'              => $username,
				'username_clean'        => $username_clean,
			);
			$sql = 'INSERT INTO ' . LOGIN_ATTEMPT_TABLE . $this->db->sql_build_array('INSERT', $attempt_data);
			$this->db->sql_query($sql);
		}
		else
		{
			$attempts = 0;
		}

		if (!$row)
		{
			if ($this->config['ip_login_limit_max'] && $attempts >= $this->config['ip_login_limit_max'])
			{
				return array(
					'status'    => LOGIN_ERROR_ATTEMPTS,
					'error_msg' => 'LOGIN_ERROR_ATTEMPTS',
					'user_row'  => array('user_id' => ANONYMOUS),
				);
			}

			return array(
				'status'    => LOGIN_ERROR_USERNAME,
				'error_msg' => 'LOGIN_ERROR_USERNAME',
				'user_row'  => array('user_id' => ANONYMOUS),
			);
		}

		$show_captcha = ($this->config['max_login_attempts'] && $row['user_login_attempts'] >= $this->config['max_login_attempts']) ||
			($this->config['ip_login_limit_max'] && $attempts >= $this->config['ip_login_limit_max']);

		// If there are too many login attempts, we need to check for a confirm image
		// Every auth module is able to define what to do by itself...
		if ($show_captcha)
		{
			/* @var $captcha_factory \phpbb\captcha\factory */
			$captcha_factory = $this->phpbb_container->get('captcha.factory');
			$captcha = $captcha_factory->get_instance($this->config['captcha_plugin']);
			$captcha->init(CONFIRM_LOGIN);
			$vc_response = $captcha->validate($row);
			if ($vc_response)
			{
				return array(
					'status'    => LOGIN_ERROR_ATTEMPTS,
					'error_msg' => 'LOGIN_ERROR_ATTEMPTS',
					'user_row'  => $row,
				);
			}
			else
			{
				$captcha->reset();
			}

		}

		// Check password ...
		if ($this->passwords_manager->check($password, $row['user_password'], $row))
		{
			// Check for old password hash...
			if ($this->passwords_manager->convert_flag || strlen($row['user_password']) == 32)
			{
				$hash = $this->passwords_manager->hash($password);

				// Update the password in the users table to the new format
				$sql = 'UPDATE ' . USERS_TABLE . "
					SET user_password = '" . $this->db->sql_escape($hash) . "'
					WHERE user_id = {$row['user_id']}";
				$this->db->sql_query($sql);

				$row['user_password'] = $hash;
			}

			$sql = 'DELETE FROM ' . LOGIN_ATTEMPT_TABLE . '
				WHERE user_id = ' . $row['user_id'];
			$this->db->sql_query($sql);

			if ($row['user_login_attempts'] != 0)
			{
				// Successful, reset login attempts (the user passed all stages)
				$sql = 'UPDATE ' . USERS_TABLE . '
					SET user_login_attempts = 0
					WHERE user_id = ' . $row['user_id'];
				$this->db->sql_query($sql);
			}

			// User inactive...
			if ($row['user_type'] == USER_INACTIVE || $row['user_type'] == USER_IGNORE)
			{
				return array(
					'status'    => LOGIN_ERROR_ACTIVE,
					'error_msg' => 'ACTIVE_ERROR',
					'user_row'  => $row,
				);
			}

			// Successful login... set user_login_attempts to zero...
			return array(
				'status'    => LOGIN_SUCCESS,
				'error_msg' => false,
				'user_row'  => $row,
			);
		}

		// Password incorrect - increase login attempts
		$sql = 'UPDATE ' . USERS_TABLE . '
			SET user_login_attempts = user_login_attempts + 1
			WHERE user_id = ' . (int) $row['user_id'] . '
				AND user_login_attempts < ' . LOGIN_ATTEMPTS_MAX;
		$this->db->sql_query($sql);

		// Give status about wrong password...
		return array(
			'status'    => ($show_captcha) ? LOGIN_ERROR_ATTEMPTS : LOGIN_ERROR_PASSWORD,
			'error_msg' => 'LOGIN_ERROR_PASSWORD',
			'user_row'  => $row,
		);
	}



	/**
	 * {@inheritdoc}
	 */
	public function login($username, $password)
	{
		// Auth plugins get the password untrimmed.
		// For compatibility we trim() here.
		$password = trim($password);

		// do not allow empty password
		if (!$password)
		{
			return array(
				'status'    => LOGIN_ERROR_PASSWORD,
				'error_msg' => 'NO_PASSWORD_SUPPLIED',
				'user_row'  => array('user_id' => ANONYMOUS),
			);
		}

		if (!$username)
		{
			return array(
				'status'    => LOGIN_ERROR_USERNAME,
				'error_msg' => 'LOGIN_ERROR_USERNAME',
				'user_row'  => array('user_id' => ANONYMOUS),
			);
		}

		$username_clean = utf8_clean_string($username);

		$sql = 'SELECT *
			FROM ' . USERS_TABLE . "
			WHERE username_clean = '" . $this->db->sql_escape($username_clean) . "'";
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (empty($row))
		{
			return array(
				'status'    => LOGIN_ERROR_USERNAME,
				'error_msg' => 'LOGIN_ERROR_USERNAME',
				'user_row'  => array('user_id' => ANONYMOUS),
			);
		}


		$result = $this->authenticate($username, $password);
		if ($result['status'] == COG_LOGIN_NO_USER)
		{
			$response = $this->migrate_user($username, $password, array('email' => $row['user_email']));
		}

		if ($result['status'] == LOGIN_SUCCESS)
		{
			$result['user_row'] = $row;
		}
		error_log($result['status']);
		//var_dump($result);
		//die();
		return $result;

	}





	/**
	 * @param string $username
	 * @param string $password
	 * @param string $email
	 *
	 * @return array
	 * @throws ChallengeException
	 * @throws \Exception
	 */
	public function authenticate($username, $password)
	{
		$error_message = '';
		$response = null;
		try {
			$response = $this->client->adminInitiateAuth(array(
				'AuthFlow' => 'ADMIN_NO_SRP_AUTH',
				'AuthParameters' => array(
					'USERNAME' => $username,
					'PASSWORD' => $password,
					'SECRET_HASH' => $this->cognitoSecretHash($username),
				),
				'ClientId' => $this->client_id,
				'UserPoolId' => $this->user_pool_id,
			));

			$response = $this->handleAuthenticateResponse($response->toArray());
			$status = COG_LOGIN_SUCCESS;

		} catch (CognitoIdentityProviderException $e) {
			switch ($e->getAwsErrorCode())
			{
				case 'UserNotFoundException':
					$status = COG_LOGIN_NO_USER;
				break;
				case 'NotAuthorizedException':
					error_log('AWS ERROR: ' . $e->getAwsErrorMessage());
					$status = COG_LOGIN_ERROR_PASSWORD;
					switch ($e->getAwsErrorMessage())
					{
						case 'Password attempts exceeded':
							$error_message = 'LOGIN_ERROR_ATTEMPTS';
						break;

						case 'User is disabled':
							$error_message = 'ACCOUNT_DEACTIVATED';
						break;

						default:
							$error_message = 'LOGIN_ERROR_PASSWORD';
					}
				break;

				default;
					$status = COG_LOGIN_NO_AUTH;
					error_log('Unhandled Authentication AWS Message: ' . $e->getAwsErrorMessage());
					//throw $e;
			}
		}
		return array(
			 'status'    => $status,
			 'error_msg' => $error_message,
			 'responce' => $response,
		 );
	}

	/**
	 * @param string $username
	 * @param string $password
	 * @param array $attributes
	 * @return string
	 * @throws /Exception
	 */
	public function migrate_user($username, $password, array $attributes = array())
	{
		error_log('--- User Migration --');
		$user_attributes = $this->buildAttributesArray($attributes);
		//$secret = gen_rand_string_friendly(24);

		try {
			$response = $this->client->AdminCreateUser(array(
				'UserPoolId' => $this->user_pool_id,
				'Username' => $username,
				'TemporaryPassword' => $password,
				'MessageAction' => 'SUPPRESS',
				'SecretHash' => $this->cognitoSecretHash($username),
				'UserAttributes' => $user_attributes,
			));
			}
		catch (CognitoIdentityProviderException $e) {
			error_log('Error Migration Message    : ' . $e->getMessage());
			throw $e;
		}

		try {
			$response = $this->client->adminInitiateAuth(array(
				'AuthFlow' => 'ADMIN_NO_SRP_AUTH',
				'AuthParameters' => array(
					'USERNAME' => $username,
					'PASSWORD' => $password,
					'SECRET_HASH' => $this->cognitoSecretHash($username),
				),
				'ClientId' => $this->client_id,
				'UserPoolId' => $this->user_pool_id,
			));
			//return $this->handleAuthenticateResponse($response->toArray());
		} catch (CognitoIdentityProviderException $e) {
			error_log('M Authentication Message    : ' . $e->getMessage());
			error_log('M Authentication AWS Message: ' . $e->getAwsErrorMessage());
			error_log('M Authentication: ErrorCode : ' . $e->getAwsErrorCode());
			error_log('M Authentication: ErrorCode : ' . $e->getAwsErrorType());
			throw $e;
		}

		error_log('Challenge Name: ' . $response['ChallengeName']);
		switch ($response['ChallengeName'])
		{
			case 'NEW_PASSWORD_REQUIRED':
				$params = array('ChallengeName'      => "NEW_PASSWORD_REQUIRED",
								'ClientId'           => $this->client_id,
								'UserPoolId'         => $this->user_pool_id,
								'ChallengeResponses' => array(
									'NEW_PASSWORD'	=> $password,
									'USERNAME'   	=> $username,
									'SECRET_HASH'	=> $this->cognitoSecretHash($username)),
								'Session' => $response['Session']
				);
				$response = $this->client->adminRespondToAuthChallenge($params);
			break;

			default:
				error_log('Unhandled responce');
				$response = null;
		}
		return $response;
	}


	/**
	 * @param array $response
	 * @return array
	 * @throws \Exception
	 */
	protected function handleAuthenticateResponse(array $response)
	{
		if (isset($response['AuthenticationResult'])) {
			// login sucsess
			return $response['AuthenticationResult'];
		}

		//if (isset($response['ChallengeName'])) {


		//}

		throw new Exception('Could not handle AdminInitiateAuth response');
	}


	/**
	 * @param array $attributes
	 * @return array
	 */
	private function buildAttributesArray(array $attributes)
	{
		$userAttributes = array();
		foreach ($attributes as $key => $value) {
			$userAttributes[] = array(
				'Name' => (string)$key,
				'Value' => (string)$value,
			);
		}
		return $userAttributes;
	}

	/**
	 * @param string $username
	 *
	 * @return string
	 */
	public function cognitoSecretHash($username)
	{
		return $this->hash($username . $this->client_id);
	}

	/**
	 * @param string $message
	 *
	 * @return string
	 */
	protected function hash($message)
	{
		$hash = hash_hmac(
			'sha256',
			$message,
			$this->client_secret,
			true
		);

		return base64_encode($hash);
	}


	public function acp()
	{
		// These are fields required in the config table
		return array(
			'cogauth_aws_region',
			'cogauth_aws_secret',
			'cogauth_aws_key',
			'cogauth_client_id',
			'cogauth_pool_id',
			'cogauth_client_secret'
		);
	}

	public function get_acp_template($new_config)
	{
		return array(
			'TEMPLATE_FILE'	=> '@mrfg_cogauth/auth_provider_cogauth.html',
			'TEMPLATE_VARS'	=> array(
				'COGAUTH_AWS_REGION' => $new_config['cogauth_aws_region'],
				'COGAUTH_AWS_KEY' => $new_config['cogauth_aws_key'],
				'COGAUTH_AWS_SECRET' => $new_config['cogauth_aws_secret'],
				'COGAUTH_POOL_ID' => $this->user_pool_id,
				'COGAUTH_CLIENT_ID' => $this->client_id,
				'COGAUTH_CLIENT_SECRET' => $this->client_secret,
			)
		);
	}

}