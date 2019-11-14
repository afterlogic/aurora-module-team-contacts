<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\TeamContacts;

/**
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2019, Afterlogic Corp.
 *
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
	public function init() 
	{
		$this->subscribeEvent('Contacts::GetStorages', array($this, 'onGetStorages'));
		$this->subscribeEvent('Core::CreateUser::after', array($this, 'onAfterCreateUser'));
		$this->subscribeEvent('Core::DeleteUser::before', array($this, 'onBeforeDeleteUser'));
		$this->subscribeEvent('Contacts::GetContacts::before', array($this, 'prepareFiltersFromStorage'));
		$this->subscribeEvent('Contacts::GetContactsInfo::before', array($this, 'prepareFiltersFromStorage'));
		$this->subscribeEvent('Contacts::Export::before', array($this, 'prepareFiltersFromStorage'));
		$this->subscribeEvent('Contacts::GetContactsByEmails::before', array($this, 'prepareFiltersFromStorage'));
		$this->subscribeEvent('Contacts::GetContacts::after', array($this, 'onAfterGetContacts'));
		$this->subscribeEvent('Contacts::GetContact::after', array($this, 'onAfterGetContact'));
		$this->subscribeEvent('Core::DoServerInitializations::after', array($this, 'onAfterDoServerInitializations'));
		$this->subscribeEvent('Contacts::CheckAccessToObject::after', array($this, 'onAfterCheckAccessToObject'));
	}
	
	public function onGetStorages(&$aStorages)
	{
		$aStorages[] = 'team';
	}
	
	private function createContactForUser($iUserId, $sEmail)
	{
		$mResult = false;
		if (0 < $iUserId)
		{
			$aContact = array(
				'Storage' => 'team',
				'PrimaryEmail' => \Aurora\Modules\Contacts\Enums\PrimaryEmail::Business,
				'BusinessEmail' => $sEmail
			);
			$oContactsDecorator = \Aurora\Modules\Contacts\Module::Decorator();
			if ($oContactsDecorator)
			{
				$aCurrentUserSession = \Aurora\Api::GetUserSession();
				\Aurora\Api::GrantAdminPrivileges();
				$mResult =  $oContactsDecorator->CreateContact($aContact, $iUserId);
				\Aurora\Api::SetUserSession($aCurrentUserSession);
			}
		}
		return $mResult;
	}
	
	public function onAfterCreateUser($aArgs, &$mResult)
	{
		$iUserId = isset($mResult) && (int) $mResult > 0 ? $mResult : 0;

		return $this->createContactForUser($iUserId, $aArgs['PublicId']);
	}
	
	public function onBeforeDeleteUser(&$aArgs, &$mResult)
	{
		$sStorage = 'team';
		$oContactsDecorator = \Aurora\Modules\Contacts\Module::Decorator();
		if ($oContactsDecorator)
		{
			$oApiContactsManager = $oContactsDecorator->GetApiContactsManager();
			$aUserContacts = $oApiContactsManager->getContactUids([
					'$AND' => [
						'IdUser' => [$aArgs['UserId'], '='],
						'Storage' => [$sStorage, '=']
					]
				]			
			);
			if (\count($aUserContacts) === 1)
			{
				$oContactsDecorator->DeleteContacts($aArgs['UserId'], $sStorage, [$aUserContacts[0]]);
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
		$oContactsDecorator = \Aurora\Modules\Contacts\Module::Decorator();
		$oApiContactsManager = $oContactsDecorator ? $oContactsDecorator->GetApiContactsManager() : null;
		if ($oApiContactsManager && $oUser && ($oUser->Role === \Aurora\System\Enums\UserRole::SuperAdmin || $oUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin))
		{
			$iTenantId = isset($aArgs['TenantId']) ? $aArgs['TenantId'] : 0;
			$aUsers = \Aurora\Modules\Core\Module::Decorator()->GetUsers($iTenantId);
			if (is_array($aUsers) && is_array($aUsers['Items']))
			{
				foreach ($aUsers['Items'] as $aUser)
				{
					if (is_array($aUser) && isset($aUser['Id']))
					{
						$aFilters = [
							'IdUser' => [$aUser['Id'], '='],
							'Storage' => ['team', '='],
						];

						$aContacts = $oApiContactsManager->getContacts(\Aurora\Modules\Contacts\Enums\SortField::Name, \Aurora\System\Enums\SortOrder::ASC, 0, 0, $aFilters);

						if (count($aContacts) === 0)
						{
							$this->createContactForUser($aUser['Id'], $aUser['PublicId']);
						}
					}
				}
			}
		}
	}

	public function onAfterCheckAccessToObject(&$aArgs, &$mResult)
	{
		$oUser = $aArgs['User'];
		$oContact = isset($aArgs['Contact']) ? $aArgs['Contact'] : null;

		if ($oContact instanceof \Aurora\Modules\Contacts\Classes\Contact && $oContact->Storage === 'team')
		{
			if ($oUser->Role !== \Aurora\System\Enums\UserRole::SuperAdmin && $oUser->IdTenant !== $oContact->IdTenant)
			{
				$mResult = false;
			}
			else
			{
				$mResult = true;
			}
		}
	}
}
