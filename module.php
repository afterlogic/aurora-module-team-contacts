<?php

class GlobalContactsModule extends AApiModule
{
	public function init() 
	{
		$this->subscribeEvent('Contacts::GetStorage', array($this, 'onGetStorage'));
		$this->subscribeEvent('AdminPanelWebclient::CreateUser::after', array($this, 'onAfterCreateUser'));
		$this->subscribeEvent('Contacts::GetContacts::before', array($this, 'onBeforeGetContacts'));
	}
	
	public function onGetStorage(&$aStorages)
	{
		$aStorages[] = 'global';
	}
	
	public function onAfterCreateUser($aArgs, &$mResult)
	{
		$iUserId = isset($mResult) && (int) $mResult > 0 ? $mResult : 0;
		if (0 < $iUserId)
		{
			$aContact = array(
				'Storage' => 'global',
				'PrimaryEmail' => EContactsPrimaryEmail::Business,
				'BusinessEmail' => $aArgs['PublicId']
			);
			$oContactsDecorator = \CApi::GetModuleDecorator('Contacts');
			if ($oContactsDecorator)
			{
				return $oContactsDecorator->CreateContact($aContact, $iUserId);
			}
		}
		return false;
	}
	
	public function onBeforeGetContacts(&$aArgs, &$mResult)
	{
		if (isset($aArgs['Storage']) && $aArgs['Storage'] === 'global')
		{
			if (!isset($aArgs['Filters']) || !is_array($aArgs['Filters']))
			{
				$aArgs['Filters'] = array();
			}
			$aArgs['Filters']['Storage'] = 'global';
		}
	}
}