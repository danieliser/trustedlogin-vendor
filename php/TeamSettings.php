<?php
/**
 * Class: TrustedLogin Team Settings
 *
 * @package trustedlogin-vendor
 * @version 0.10.0
 */

namespace TrustedLogin\Vendor;

/**
 * Object-representation of one Team's settings.
 */
class TeamSettings
{

	const HELPDESK_SETTINGS = 'helpdesk_settings';


	/**
	 * @var array
	 * @since 0.10.0
	 */
	protected $defaults;


	/**
	 * @var array
	 * @since 0.10.0
	 */
	protected $values;


	/**
	 * @since 0.10.0
	 *
	 * @param array $values Values to set
	 */
	public function __construct(array $values = [])
	{
		$this->defaults  = [
			'account_id'       => '',
			'private_key'      => '',
			'api_key'       => '',
			'helpdesk'         => 'helpscout',
			'approved_roles'   => [ 'administrator' ],
			'debug_enabled'    => 'on',
			'enable_audit_log' => 'on',
			'connected' => false,
			'status' => false,
			'name' => '',
			self::HELPDESK_SETTINGS => [

			]
		];
		$this->reset($values);
	}

	public function toArray(){
		if( ! is_array($this->values['helpdesk'])){
			$this->values['helpdesk'] = [ $this->values['helpdesk'] ];
		}
		return $this->values;
	}

	/**
	 * Get array of helpdesks that are enabled.
	 *
	 * @since 0.10.0
	 *
	 * @param array $values Values to set
	 */
	public function getHelpdesks( $helpdesks = null){
		if( empty( $helpdesks ) ){
			$helpdesks = $this->get('helpdesk');
		}
		if( is_string($helpdesks)){
			$helpdesks = [$helpdesks];
		}
		if( empty($helpdesks)){
			return ['helpscout'];
		}
		return $helpdesks;
	}

	/**
	 * Reset all values
	 *
	 * @since 0.10.0
	 *
	 * @param array $values Values to set
	 * @return $this
	 */
	public function reset(array $values)
	{
		$this->values = [];
		foreach ($this->defaults as $key => $default) {
			if (isset($values[$key])&& ! empty($values[$key])) {
				$value = $values[$key];
				if( is_object($value)){
					$value = (array)$value;
					foreach ($value as $k => $v) {
						if( is_object($v)){
							$value[$k] = (array)$v;
						}
					}
				}

				$this->values[$key] = $value;
			} else {
				$this->values[$key] = $default;
			}
		}
		if( empty( $this->values['approved_roles'])){
			$this->values['approved_roles'] = [ 'administrator' ];
		}
		if( empty( $this->values['helpdesk'])){
			$this->values['helpdesk'] = [ 'helpscout' ];
		}
		return $this;
	}

	/**
	 * Set a value
	 *
	 * @since 0.10.0
	 *
	 * @param string $key Setting to set
	 * @param mixed $value The new value
	 * @return $this
	 */
	public function set($key, $value)
	{
		if ($this->valid($key)) {
			$this->values[$key] = $value;
		} else {
			throw new \Exception('Invalid key');
		}
		return $this;
	}

	/**
	 * Get a value
	 *
	 * @since 0.10.0
	 * @param string $key Setting to get
	 * @return mixed
	 */
	public function get($key)
	{
		if ($this->valid($key)) {
			$value = $this->values[$key];
			if( is_object($value)){
				$value = (array)$value;
			}
			return $value;
		}
		throw new \Exception('Invalid key');
	}

	/**
	 * Check if key is valid
	 *
	 * @since 0.10.0
	 * @param string $key Setting to get
	 * @return bool
	 */
	public function valid($key)
	{
		return array_key_exists($key, $this->defaults);
	}

	/**
	 * Get settings for current helpdesk data
	 *
	 * @since 0.10.0
	 * @return array
	 */
	public function getHelpdeskData()
	{
		$helpdesks = $this->get('helpdesk');
		if( empty( $helpdesks)){
			$helpdesks = ['helpscout'];
			$this->set( 'helpdesk', $helpdesks);
		}
		if( ! is_array($helpdesks)){
			$helpdesks = [$helpdesks];
		}
		$helpdeskSettings = $this->get(self::HELPDESK_SETTINGS,[]);
		if ($helpdeskSettings){
			$helpdesk = $helpdesks[0];
			if(  isset($helpdeskSettings[$helpdesk])) {
				$data = $helpdeskSettings[$helpdesk];
				if( is_object($data)){
					$data=(array)$data;
				}
				return [
					'secret' => isset($data['secret']) ?$data['secret'] :"",
					'callback' => isset($data['callback']) ?$data['callback'] :"",
				];
			}

		}


	}
}
