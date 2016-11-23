<?php

class GlobalContactsModule extends AApiModule
{
	public function init() 
	{
		$this->subscribeEvent('Contacts::GetStorage', array($this, 'onGetStorage'));
		$this->subscribeEvent('AdminPanelWebclient::CreateUser::after', array($this, 'onAfterCreateUser'));
		$this->subscribeEvent('AdminPanelWebclient::DeleteEntity::before', array($this, 'onBeforeDeleteEntity'));
		$this->subscribeEvent('Contacts::GetContacts::before', array($this, 'onBeforeGetContacts'));
		$this->subscribeEvent('Core::DoServerInitializations::after', array($this, 'onAfterDoServerInitializations'));
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
	
	public function onBeforeDeleteEntity(&$aArgs, &$mResult)
	{
		if ($aArgs['Type'] === 'User')
		{
			$oContactsDecorator = \CApi::GetModuleDecorator('Contacts');
			if ($oContactsDecorator)
			{
				$aFilters = [
					'$AND' => [
						'IdUser' => [$aArgs['Id'], '='],
						'Storage' => ['global', '=']
					]
				];
				$oApiContactsManager = $oContactsDecorator->GetApiContactsManager();
				$aUserContacts = $oApiContactsManager->getContactItems(EContactSortField::Name, ESortOrder::ASC, 0, 0, $aFilters, 0);
				if (count($aUserContacts) === 1)
				{
					$oContactsDecorator->DeleteContacts([$aUserContacts[0]->iId]);
				}
			}
		}
	}
	
	public function onBeforeGetContacts(&$aArgs, &$mResult)
	{
		if (isset($aArgs['Storage']) && ($aArgs['Storage'] === 'global' || $aArgs['Storage'] === 'all'))
		{
			if (!isset($aArgs['Filters']) || !is_array($aArgs['Filters']))
			{
				$aArgs['Filters'] = array();
			}
			$oUser = \CApi::getAuthenticatedUser();
			$aArgs['Filters'][]['$AND'] = [
				'IdTenant' => [$oUser->IdTenant, '='],
				'Storage' => ['global', '='],
			];
		}
	}
	
	public function onAfterDoServerInitializations($aArgs, &$mResult)
	{
		//sync users with global contacts
	}
}