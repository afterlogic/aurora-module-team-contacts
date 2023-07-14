<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\TeamContacts;

use Aurora\Api;
use Aurora\Modules\Contacts\Enums\PrimaryEmail;
use Aurora\Modules\Contacts\Enums\SortField;
use Aurora\Modules\Contacts\Enums\StorageType;
use Aurora\Modules\Contacts\Models\Contact;
use Aurora\Modules\Contacts\Module as ContactsModule;
use Aurora\System\Enums\UserRole;

/**
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2023, Afterlogic Corp.
 *
 * @property Settings $oModuleSettings
 *
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
    protected static $iStorageOrder = 20;

    public function init()
    {
        $this->subscribeEvent('Contacts::GetStorages', array($this, 'onGetStorages'));
        $this->subscribeEvent('Core::CreateUser::after', array($this, 'onAfterCreateUser'));
//        $this->subscribeEvent('Core::DeleteUser::after', array($this, 'onAfterDeleteUser'));
        $this->subscribeEvent('Contacts::PrepareFiltersFromStorage', array($this, 'prepareFiltersFromStorage'));
        $this->subscribeEvent('Contacts::GetContacts::after', array($this, 'onAfterGetContacts'));
        $this->subscribeEvent('Contacts::GetContact::after', array($this, 'onAfterGetContact'));
        $this->subscribeEvent('Core::DoServerInitializations::after', array($this, 'onAfterDoServerInitializations'));
        $this->subscribeEvent('Contacts::CheckAccessToObject::after', array($this, 'onAfterCheckAccessToObject'));
        $this->subscribeEvent('Contacts::GetContactSuggestions', array($this, 'onGetContactSuggestions'));
    }

    /**
     * @return Module
     */
    public static function getInstance()
    {
        return parent::getInstance();
    }

    /**
     * @return Module
     */
    public static function Decorator()
    {
        return parent::Decorator();
    }

    /**
     * @return Settings
     */
    public function getModuleSettings()
    {
        return $this->oModuleSettings;
    }

    public function onGetStorages(&$aStorages)
    {
        $aStorages[self::$iStorageOrder] = StorageType::Team;
    }

    private function createContactForUser($iUserId, $sEmail)
    {
        $mResult = false;
        if (0 < $iUserId) {
            $aContact = array(
                'Storage' => StorageType::Team,
                'PrimaryEmail' => PrimaryEmail::Business,
                'BusinessEmail' => $sEmail
            );

            $aCurrentUserSession = \Aurora\Api::GetUserSession();
            \Aurora\Api::GrantAdminPrivileges();
            $mResult =  \Aurora\Modules\Contacts\Module::Decorator()->CreateContact($aContact, $iUserId);
            \Aurora\Api::SetUserSession($aCurrentUserSession);
        }
        return $mResult;
    }

    public function onAfterCreateUser($aArgs, &$mResult)
    {
        $iUserId = isset($mResult) && (int) $mResult > 0 ? $mResult : 0;

        return $this->createContactForUser($iUserId, $aArgs['PublicId']);
    }

    public function onAfterDeleteUser(&$aArgs, &$mResult)
    {
        if ($mResult) {
            Contact::where([['IdUser', '=', $aArgs['UserId']], ['Storage', '=', StorageType::Team]])->delete();
        }
    }

    public function prepareFiltersFromStorage(&$aArgs, &$mResult)
    {
        if (isset($aArgs['Storage']) && ($aArgs['Storage'] === StorageType::Team || $aArgs['Storage'] === StorageType::All)) {
            $aArgs['IsValid'] = true;

            if (!isset($mResult)) {
                $mResult = \Aurora\Modules\Contacts\Models\Contact::query();
            }

            $oUser = \Aurora\System\Api::getAuthenticatedUser();

            $mResult = $mResult->orWhere(function ($query) use ($oUser) {
                $query = $query->where('IdTenant', $oUser->IdTenant)
                    ->where('Storage', StorageType::Team);
                // if (isset($aArgs['SortField']) && $aArgs['SortField'] === SortField::Frequency) {
                //     $query->whereNotNull('DateModified');
                // }
            });
        }
    }

    public function onAfterGetContacts($aArgs, &$mResult)
    {
        if (\is_array($mResult) && \is_array($mResult['List'])) {
            foreach ($mResult['List'] as $iIndex => $aContact) {
                if ($aContact['Storage'] === StorageType::Team) {
                    $iUserId = \Aurora\System\Api::getAuthenticatedUserId();
                    if ($aContact['IdUser'] === $iUserId) {
                        $aContact['ItsMe'] = true;
                    } else {
                        $aContact['ReadOnly'] = true;
                    }
                    $mResult['List'][$iIndex] = $aContact;
                }
            }
        }
    }

    public function onAfterGetContact($aArgs, &$mResult)
    {
        $authenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
        if ($mResult && $authenticatedUser && $mResult->Storage === StorageType::Team) {
            $allowEditTeamContactsByTenantAdmins = ContactsModule::getInstance()->oModuleSettings->AllowEditTeamContactsByTenantAdmins;
            $isUserTenantAdmin = $authenticatedUser->Role === UserRole::TenantAdmin;
            $isContactInTenant = $mResult->IdTenant === $authenticatedUser->IdTenant;
            if ($mResult->IdUser === $authenticatedUser->Id) {
                $mResult->ExtendedInformation['ItsMe'] = true;
            } elseif (!($allowEditTeamContactsByTenantAdmins && $isUserTenantAdmin && $isContactInTenant)) {
                $mResult->ExtendedInformation['ReadOnly'] = true;
            }
        }
    }

    /**
     * Creates team contacts if they are missing within current tenant.
     */
    public function onAfterDoServerInitializations($aArgs, &$mResult)
    {
        $oUser = \Aurora\System\Api::getAuthenticatedUser();
        if ($oUser && $oUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin) {
            $aUsers = \Aurora\Modules\Core\Module::Decorator()->GetUsers($oUser->IdTenant);

            if (is_array($aUsers) && is_array($aUsers['Items']) && count($aUsers['Items']) > 0) {
                $aUserIds = array_map(
                    function ($aUser) {
                        if (is_array($aUser) && isset($aUser['Id'])) {
                            return $aUser['Id'];
                        }
                    },
                    $aUsers['Items']
                );

                $aContactsIdUsers = Contact::whereIn('IdUser', $aUserIds)
                    ->where('Storage', StorageType::Team)
                    ->get('IdUser')
                    ->map(function ($oUser) {
                        return $oUser->IdUser;
                    })->toArray();

                $aDiffIds = array_diff($aUserIds, $aContactsIdUsers);
                if (is_array($aDiffIds) && count($aDiffIds) > 0) {
                    foreach ($aDiffIds as $iId) {
                        $aUsersFilter = array_filter($aUsers['Items'], function ($aUser) use ($iId) {
                            return $aUser['Id'] === $iId;
                        });
                        if (is_array($aUsersFilter) && count($aUsersFilter) > 0) {
                            foreach ($aUsersFilter as $oUser) {
                                $this->createContactForUser($iId, $oUser['PublicId']);
                            }
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

        if ($oContact instanceof \Aurora\Modules\Contacts\Models\Contact && $oContact->Storage === StorageType::Team) {
            if ($oUser->Role !== \Aurora\System\Enums\UserRole::SuperAdmin && $oUser->IdTenant !== $oContact->IdTenant) {
                $mResult = false;
            } else {
                $mResult = true;
            }
        }
    }

    public function onGetContactSuggestions(&$aArgs, &$mResult)
    {
        if ($aArgs['Storage'] === 'all' || $aArgs['Storage'] === StorageType::Team) {
            $mResult[StorageType::Team] = \Aurora\Modules\Contacts\Module::Decorator()->GetContacts(
                $aArgs['UserId'],
                StorageType::Team,
                0,
                $aArgs['Limit'],
                $aArgs['SortField'],
                $aArgs['SortOrder'],
                $aArgs['Search']
            );
        }
    }
}
