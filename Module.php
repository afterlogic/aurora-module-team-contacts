<?php
/**
 * This code is licensed under AGPLv3 license or AfterLogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\TeamContacts;

/**
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing AfterLogic Software License
 * @copyright Copyright (c) 2018, Afterlogic Corp.
 *
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
	public function init() 
	{
		$this->subscribeEvent('Contacts::GetStorage', array($this, 'onGetStorage'));
		$this->subscribeEvent('Core::CreateUser::after', array($this, 'onAfterCreateUser'));
		$this->subscribeEvent('Core::DeleteUser::before', array($this, 'onBeforeDeleteUser'));
		$this->subscribeEvent('Contacts::GetContacts::before', array($this, 'prepareFiltersFromStorage'));
		$this->subscribeEvent('Contacts::Export::before', array($this, 'prepareFiltersFromStorage'));
		$this->subscribeEvent('Contacts::GetContactsByEmails::before', array($this, 'prepareFiltersFromStorage'));
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
				'PrimaryEmail' => \Aurora\Modules\Contacts\Enums\PrimaryEmail::Business,
				'BusinessEmail' => $sEmail
			);
			$oContactsDecorator = \Aurora\Modules\Contacts\Module::Decorator();
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
	
	public function onBeforeDeleteUser(&$aArgs, &$mResult)
	{
		$oContactsDecorator = \Aurora\Modules\Contacts\Module::Decorator();
		if ($oContactsDecorator)
		{
			$oApiContactsManager = $oContactsDecorator->GetApiContactsManager();
			$aUserContacts = $oApiContactsManager->getContactUids([
					'$AND' => [
						'IdUser' => [$aArgs['UserId'], '='],
						'Storage' => ['team', '=']
					]
				]			
			);
			if (\count($aUserContacts) === 1)
			{
				$oContactsDecorator->DeleteContacts([$aUserContacts[0]]);
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
		$oCoreDecorator = \Aurora\Modules\Core\Module::Decorator();
		$oContactsDecorator = \Aurora\Modules\Contacts\Module::Decorator();
		$oApiContactsManager = $oContactsDecorator ? $oContactsDecorator->GetApiContactsManager() : null;
		if ($oApiContactsManager && $oCoreDecorator && $oUser && ($oUser->Role === \Aurora\System\Enums\UserRole::SuperAdmin || $oUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin))
		{
			$aUsers = $oCoreDecorator->GetUserList();
			foreach ($aUsers as $aUser)
			{
				$aFilters = [
					'IdUser' => [$aUser['Id'], '='],
					'Storage' => ['team', '='],
				];

				$aContacts = $oApiContactsManager->getContacts(\Aurora\Modules\Contacts\Enums\SortField::Name, \Aurora\System\Enums\SortOrder::ASC, 0, 0, $aFilters, 0);
				
				if (count($aContacts) === 0)
				{
					$this->createContactForUser($aUser['Id'], $aUser['PublicId']);
				}
			}
		}
	}
}
