<?php
/**
 * Class: TrustedLogin Settings API
 *
 * @package trustedlogin-vendor
 * @version 0.10.0
 */

namespace TrustedLogin\Vendor;

use \WP_Error;
use \Exception;

class SettingsApi
{

	const TEAM_SETTING_NAME = 'trustedlogin_vendor_team_settings';

	/**
	 * @var TeamSettings[]
	 * @since 0.10.0
	 */
	protected $team_settings = [];

	/**
	 * @param TeamSettings[]|array[] $team_data Collection of team data
	 * @since 0.10.0
	 */	public function __construct(array $team_data)
	{
		foreach ($team_data as $values) {
			if (is_array($values)) {
				$values = new TeamSettings($values);
			}
			if (is_a($values, TeamSettings::class)) {
				$this->team_settings[] = $values;
			}
		}
	}

	/**
	 * Create instance from saved data.
	 * @since 0.10.0
	 * @return SettingsApi
	 */
	public static function from_saved()
	{
		$saved = get_option(self::TEAM_SETTING_NAME, []);

		$data = [];
		if (! empty($saved)) {
			$saved = (array)json_decode($saved);
			foreach ($saved as $value) {
				$team = new TeamSettings((array)$value);
				$data[] = $team;
			}
		}

		return new self($data);
	}

	/**
	 * Save data
	 *
	 * @since 0.10.0
	 * @return SettingsApi
	 */
	public function save()
	{
		$data = [];
		foreach ($this->team_settings as $setting) {
			$_setting = $setting->to_array();
			//If enabled a helpdesk...
			if( ! empty( $setting->get_helpdesks() ) ) {
				//And don't have helpdesk settings...
				if( empty( $setting->get_helpdesk_data())){
					//...then add them.
					$_setting[TeamSettings::HELPDESK_SETTINGS] = [];
					$account_id = $setting->get( 'account_id');
					foreach( $setting->get_helpdesks() as $helpdesk){
						$_setting[TeamSettings::HELPDESK_SETTINGS][$helpdesk] = [
							'secret' => AccessKeyLogin::makeSecret( $account_id ),
							'callback' => AccessKeyLogin::url( $account_id )
						];
					}
				}
			}
			$data[] = $_setting;



		}
		update_option(self::TEAM_SETTING_NAME, json_encode($data));
		return $this;
	}

	/**
	 * Get team setting, by id
	 *
	 * @since 0.10.0
	 * @param string|int $account_id Account to search for
	 * @return TeamSettings
	 */
	public function get_by_account_id($account_id)
	{
		foreach ($this->team_settings as $setting) {
			if ($account_id === $setting->get('account_id')) {
				return $setting;
			}
		}

		throw new \Exception($account_id. ' Not found');
	}

	/*
	 * Update team setting
	 *
	 * @since 0.10.0
	 * @param TeamSettings $values New settings object
	 * @return SettingsApi
	 */
	public function update_by_account_id(TeamSettings $value)
	{
		foreach ($this->team_settings as $key => $setting) {
			if ($value->get('account_id') == $setting->get('account_id')) {
				$this->team_settings[$key] = $value;
				return $this;
			}
		}
		throw new \Exception('Canot save, Account not found');
	}

	/**
	 * Check if setting is in collection
	 * @since 0.10.0
	 * @param string $account_id
	 * @return bool
	 */
	public function has_setting($account_id)
	{
		foreach ($this->team_settings as $setting) {
			if ($account_id == $setting->get('account_id')) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Add or update a setting to collection
	 * @since 0.10.0
	 * @param TeamSetting $setting
	 * @return $this
	 */
	public function add_setting(TeamSettings $setting)
	{
		//If we have it already, update
		if ($this->has_setting($setting->get('account_id'))) {
			$this->update_by_account_id($setting);
			return $this;
		}
		//add it to collection
		$this->team_settings[] = $setting;
		return $this;
	}

	/**
	* Reset all data
	*
	* @since 0.10.0
	* @return $this
	*/
	public function reset()
	{
		$this->team_settings = [];
		return $this;
	}

	/**
	 * Convert to array of arrays
	 *
	 * @since 0.10.0
	 * @return array
	 */
	public function to_array()
	{
		$data = [
			'teams' => [],
		];
		foreach ($this->team_settings as $setting) {
			$data['teams'][] = $setting->to_array();
		}
		return $data;
	}
}
