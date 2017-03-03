<?php

namespace Aurora\Modules;

class TeamContactsModule extends \Aurora\System\Module\AbstractModule
{
	public function init() 
	{
		$this->subscribeEvent('Contacts::GetStorage', array($this, 'onGetStorage'));
		$this->subscribeEvent('AdminPanelWebclient::CreateUser::after', array($this, 'onAfterCreateUser'));
		$this->subscribeEvent('AdminPanelWebclient::DeleteEntity::before', array($this, 'onBeforeDeleteEntity'));
		$this->subscribeEvent('Contacts::GetContacts::before', array($this, 'prepareFiltersFromStorage'));
		$this->subscribeEvent('Contacts::Export::before', array($this, 'prepareFiltersFromStorage'));
		$this->subscribeEvent('Contacts::GetContacts::after', array($this, 'onAfterGetContacts'));
		$this->subscribeEvent('Contacts::GetContact::after', array($this, 'onAfterGetContact'));
		$this->subscribeEvent('Core::DoServerInitializations::after', array($this, 'onAfterDoServerInitializations'));
	}
	
	public function onGetStorage(&$aStorages)
	{
		$aStorages[] = 'team';
	}
	
	private function createContactForUser($iUserId, $sEmail)
	{
		if (0 < $iUserId)
		{
			$aContact = array(
				'Storage' => 'team',
				'PrimaryEmail' => \EContactsPrimaryEmail::Business,
				'BusinessEmail' => $sEmail
			);
			$oContactsDecorator = \Aurora\System\Api::GetModuleDecorator('Contacts');
			if ($oContactsDecorator)
			{
				return $oContactsDecorator->CreateContact($aContact, $iUserId);
			}
		}
		return false;
	}
	
	public function onAfterCreateUser($aArgs, &$mResult)
	{
		$iUserId = isset($mResult) && (int) $mResult > 0 ? $mResult : 0;
		return $this->createContactForUser($iUserId, $aArgs['PublicId']);
	}
	
	public function onBeforeDeleteEntity(&$aArgs, &$mResult)
	{
		if ($aArgs['Type'] === 'User')
		{
			$oContactsDecorator = \Aurora\System\Api::GetModuleDecorator('Contacts');
			if ($oContactsDecorator)
			{
				$aFilters = [
					'$AND' => [
						'IdUser' => [$aArgs['Id'], '='],
						'Storage' => ['team', '=']
					]
				];
				$oApiContactsManager = $oContactsDecorator->GetApiContactsManager();
				$aUserContacts = $oApiContactsManager->getContacts(\EContactSortField::Name, \ESortOrder::ASC, 0, 0, $aFilters, '');
				if (\count($aUserContacts) === 1)
				{
					$oContactsDecorator->DeleteContacts([$aUserContacts[0]->UUID]);
				}
			}
		}
	}
	
	public function prepareFiltersFromStorage(&$aArgs, &$mResult)
	{
		if (isset($aArgs['Storage']) && ($aArgs['Storage'] === 'team' || $aArgs['Storage'] === 'all'))
		{
			if (!isset($aArgs['Filters']) || !is_array($aArgs['Filters']))
			{
				$aArgs['Filters'] = array();
			}
			$oUser = \Aurora\System\Api::getAuthenticatedUser();
			$aArgs['Filters'][]['$AND'] = [
				'IdTenant' => [$oUser->IdTenant, '='],
				'Storage' => ['team', '='],
			];
		}
	}
	
	public function onAfterGetContacts($aArgs, &$mResult)
	{
		if (\is_array($mResult) && \is_array($mResult['List']))
		{
			foreach ($mResult['List'] as $iIndex => $aContact)
			{
				if ($aContact['Storage'] === 'team')
				{
					$iUserId = \Aurora\System\Api::getAuthenticatedUserId();
					if ($aContact['IdUser'] === $iUserId)
					{
						$aContact['ItsMe'] = true;
					}
					else
					{
						$aContact['ReadOnly'] = true;
					}
					$mResult['List'][$iIndex] = $aContact;
				}
			}
		}
	}
	
	public function onAfterGetContact($aArgs, &$mResult)
	{
		if ($mResult)
		{
			$iUserId = \Aurora\System\Api::getAuthenticatedUserId();
			if ($mResult->Storage === 'team')
			{
				if ($mResult->IdUser === $iUserId)
				{
					$mResult->ExtendedInformation['ItsMe'] = true;
				}
				else
				{
					$mResult->ExtendedInformation['ReadOnly'] = true;
				}
			}
		}
	}
	
	public function onAfterDoServerInitializations($aArgs, &$mResult)
	{
		$oUser = \Aurora\System\Api::getAuthenticatedUser();
		$oCoreDecorator = \Aurora\System\Api::GetModuleDecorator('Core');
		$oContactsDecorator = \Aurora\System\Api::GetModuleDecorator('Contacts');
		$oApiContactsManager = $oContactsDecorator ? $oContactsDecorator->GetApiContactsManager() : null;
		if ($oApiContactsManager && $oCoreDecorator && $oUser && ($oUser->Role === \EUserRole::SuperAdmin || $oUser->Role === \EUserRole::TenantAdmin))
		{
			$aUsers = $oCoreDecorator->GetUserList();
			foreach ($aUsers as $aUser)
			{
				$aFilters = [
					'IdUser' => [$aUser['Id'], '='],
					'Storage' => ['team', '='],
				];

				$aContacts = $oApiContactsManager->getContacts(\EContactSortField::Name, \ESortOrder::ASC, 0, 0, $aFilters, 0);
				
				if (count($aContacts) === 0)
				{
					$this->createContactForUser($aUser['Id'], $aUser['PublicId']);
				}
			}
		}
	}
}
