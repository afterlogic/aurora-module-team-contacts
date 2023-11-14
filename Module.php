<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\TeamContacts;

use Afterlogic\DAV\Backend;
use Afterlogic\DAV\Constants;
use Aurora\Api;
use Aurora\Modules\Contacts\Enums\StorageType;
use Aurora\Modules\Contacts\Module as ContactsModule;
use Aurora\System\Enums\UserRole;
use Sabre\VObject\UUIDUtil;
use Illuminate\Database\Capsule\Manager as Capsule;
use Aurora\System\Exceptions\ApiException;

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

    protected $userPublicIdToDelete = null;

    protected $teamAddressBook = null;

    public function init()
    {
        $this->subscribeEvent('Contacts::GetAddressBooks::after', array($this, 'onAfterGetAddressBooks'));
        $this->subscribeEvent('Core::CreateUser::after', array($this, 'onAfterCreateUser'));
        $this->subscribeEvent('Contacts::PrepareFiltersFromStorage', array($this, 'onPrepareFiltersFromStorage'));
        $this->subscribeEvent('Contacts::GetContacts::after', array($this, 'onAfterGetContacts'));
        $this->subscribeEvent('Contacts::GetContact::after', array($this, 'onAfterGetContact'));
        $this->subscribeEvent('Core::DoServerInitializations::after', array($this, 'onAfterDoServerInitializations'));
        $this->subscribeEvent('Contacts::CheckAccessToObject::after', array($this, 'onAfterCheckAccessToObject'));
        $this->subscribeEvent('Contacts::GetContactSuggestions', array($this, 'onGetContactSuggestions'));
        $this->subscribeEvent('Contacts::CheckAccessToAddressBook::after', array($this, 'onAfterCheckAccessToAddressBook'));

        $this->subscribeEvent('Contacts::PopulateStorage', array($this, 'populateStorage'));
        $this->subscribeEvent('Contacts::CreateContact::before', array($this, 'populateStorage'));
        $this->subscribeEvent('Contacts::ContactQueryBuilder', array($this, 'onContactQueryBuilder'));

        $this->subscribeEvent('Core::DeleteUser::before', array($this, 'onBeforeDeleteUser'));
        $this->subscribeEvent('Core::DeleteUser::after', array($this, 'onAfterDeleteUser'));

        $this->subscribeEvent('Contacts::UpdateContactObject::before', array($this, 'onBeforeUpdateContactObject'));
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

    public function onAfterGetAddressBooks(&$aArgs, &$mResult)
    {
        if (!is_array($mResult)) {
            $mResult = [];
        }

        $addressbook = $this->GetTeamAddressbook($aArgs['UserId']);
        if ($addressbook) {
            /**
             * @var array $addressbook
             */
            $mResult[] = [
                'Id' => 'team',
                'EntityId' => $addressbook['id'],
                'CTag' => $addressbook['{http://sabredav.org/ns}sync-token'],
                'Display' => true,
                'Order' => 1,
                'DisplayName' => $addressbook['{DAV:}displayname'],
                'Uri' => $addressbook['uri']
            ];
        }
    }

    public function GetTeamAddressbook($UserId)
    {
        Api::CheckAccess($UserId);

        $addressbook = false;

        $oUser = Api::getUserById($UserId);
        if ($oUser) {
            $sPrincipalUri = \Afterlogic\DAV\Constants::PRINCIPALS_PREFIX . $oUser->IdTenant . '_' . \Afterlogic\DAV\Constants::DAV_TENANT_PRINCIPAL;
            $addressbook = Backend::Carddav()->getAddressBookForUser($sPrincipalUri, 'gab');
            if (!$addressbook) {
                if (Backend::Carddav()->createAddressBook($sPrincipalUri, 'gab', ['{DAV:}displayname' => \Afterlogic\DAV\Constants::ADDRESSBOOK_TEAM_DISPLAY_NAME])) {
                    $addressbook = Backend::Carddav()->getAddressBookForUser($sPrincipalUri, 'gab');
                }
            }
        }

        return $addressbook;
    }

    private function createContactForUser($iUserId, $sEmail)
    {
        $mResult = false;
        if (0 < $iUserId) {
            $addressbook = $this->GetTeamAddressbook($iUserId);
            if ($addressbook) {
                $uid = UUIDUtil::getUUID();
                $vcard = new \Sabre\VObject\Component\VCard(['UID' => $uid]);
                $vcard->add(
                    'EMAIL',
                    $sEmail,
                    [
                        'type' => ['work'],
                        'pref' => 1,
                    ]
                );

                $mResult = !!Backend::Carddav()->createCard($addressbook['id'], $uid . '.vcf', $vcard->serialize());
            }
        }
        return $mResult;
    }

    public function onAfterCreateUser($aArgs, &$mResult)
    {
        $iUserId = isset($mResult) && (int) $mResult > 0 ? $mResult : 0;

        return $this->createContactForUser($iUserId, $aArgs['PublicId']);
    }

    public function onPrepareFiltersFromStorage(&$aArgs, &$mResult)
    {
        if (isset($aArgs['Storage']) && ($aArgs['Storage'] === StorageType::Team || $aArgs['Storage'] === StorageType::All)) {
            $aArgs['IsValid'] = true;

            $oUser = \Aurora\System\Api::getAuthenticatedUser();

            $addressbook = $this->GetTeamAddressbook($oUser->Id);
            if ($addressbook) {
                if (isset($aArgs['Query'])) {
                    $aArgs['Query']->addSelect(Capsule::connection()->raw(
                        'CASE
                        WHEN ' . Capsule::connection()->getTablePrefix() . 'adav_cards.addressbookid = ' . $addressbook['id'] . ' THEN true
                        ELSE false
                    END as IsTeam'
                    ));
                }
                $mResult = $mResult->orWhere('adav_cards.addressbookid', $addressbook['id']);
            }
        }
    }

    public function onAfterGetContacts($aArgs, &$mResult)
    {
        if (\is_array($mResult) && \is_array($mResult['List'])) {
            $userPublicId = Api::getUserPublicIdById($aArgs['UserId']);
            $teamAddressbook = $this->GetTeamAddressbook($aArgs['UserId']);
            if ($teamAddressbook) {
                foreach ($mResult['List'] as $iIndex => $aContact) {
                    if ($aContact['Storage'] == $teamAddressbook['id']) {
                        if ($aContact['ViewEmail'] === $userPublicId) {
                            $aContact['ItsMe'] = true;
                        } else {
                            $aContact['ReadOnly'] = true;
                        }
                        $mResult['List'][$iIndex] = $aContact;
                    }
                }
            }
        }
    }

    public function onAfterGetContact($aArgs, &$mResult)
    {
        $authenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
        $teamAddressbook = $this->GetTeamAddressbook($authenticatedUser->Id);
        if ($teamAddressbook) {
            if ($mResult && $authenticatedUser && $mResult->Storage == $teamAddressbook['id']) {
                $allowEditTeamContactsByTenantAdmins = ContactsModule::getInstance()->oModuleSettings->AllowEditTeamContactsByTenantAdmins;
                $isUserTenantAdmin = $authenticatedUser->Role === UserRole::TenantAdmin;
                $isContactInTenant = $mResult->IdTenant === $authenticatedUser->IdTenant;
                if ($mResult->BusinessEmail === $authenticatedUser->PublicId) {
                    $mResult->ExtendedInformation['ItsMe'] = true;
                } elseif (!($allowEditTeamContactsByTenantAdmins && $isUserTenantAdmin && $isContactInTenant)) {
                    $mResult->ExtendedInformation['ReadOnly'] = true;
                }
            }
        }
    }

    /**
     * Creates team contacts if they are missing within current tenant.
     */
    public function onAfterDoServerInitializations($aArgs, &$mResult)
    {
        $oUser = \Aurora\System\Api::getAuthenticatedUser();
        if ($oUser && $oUser->Role === \Aurora\System\Enums\UserRole::NormalUser) {
            $teamAddressBook = $this->GetTeamAddressbook($oUser->Id);
            if ($teamAddressBook) {
                $contact = Capsule::connection()->table('contacts_cards')
                ->where('AddressBookId', $teamAddressBook['id'])
                ->where('ViewEmail', $oUser->PublicId)
                ->first();
                if (!$contact) {
                    $this->createContactForUser($oUser->Id, $oUser->PublicId);
                }
            }
        }
    }

    public function onAfterCheckAccessToObject(&$aArgs, &$mResult)
    {
        $oUser = $aArgs['User'];
        $oContact = isset($aArgs['Contact']) ? $aArgs['Contact'] : null;
        $teamAddressBook = $this->GetTeamAddressbook($oUser->Id);
        if ($oContact instanceof \Aurora\Modules\Contacts\Classes\Contact && $oContact->AddressBookId === $teamAddressBook['id']) {
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

    /**
     *
     */
    public function populateStorage(&$aArgs, &$mResult)
    {
        if (isset($aArgs['Storage'], $aArgs['UserId'])) {
            $aStorageParts = \explode('-', $aArgs['Storage']);
            if (isset($aStorageParts[0]) && $aStorageParts[0] === StorageType::Team) {

                $addressbook = $this->GetTeamAddressbook($aArgs['UserId']);
                if ($addressbook) {
                    $aArgs['Storage'] = StorageType::Team;
                    $aArgs['AddressBookId'] = $addressbook['id'];
                }
            }
        }
    }

    public function onContactQueryBuilder(&$aArgs, &$query)
    {
        $addressbook = $this->GetTeamAddressbook($aArgs['UserId']);
        $query->orWhere(function ($q) use ($addressbook, $aArgs) {
            $q->where('adav_addressbooks.id', $addressbook['id']);
            if (is_array($aArgs['UUID'])) {
                if (count($aArgs['UUID']) > 0) {
                    $q->whereIn('adav_cards.id', $aArgs['UUID']);
                }
            } else {
                $q->where('adav_cards.id', $aArgs['UUID']);
            }
        });
    }

    public function onBeforeDeleteUser(&$aArgs, &$mResult)
    {
        if (isset($aArgs['UserId'])) {
            $this->userPublicIdToDelete = Api::getUserPublicIdById($aArgs['UserId']);
            $this->teamAddressBook = $this->GetTeamAddressbook($aArgs['UserId']);
        }
    }

    public function onAfterDeleteUser($aArgs, &$mResult)
    {
        if ($mResult && $this->userPublicIdToDelete && $this->teamAddressBook) {
            $card = Capsule::connection()->table('contacts_cards')
                ->join('adav_cards', 'contacts_cards.CardId', '=', 'adav_cards.id')
                ->join('adav_addressbooks', 'adav_cards.addressbookid', '=', 'adav_addressbooks.id')
                ->where('adav_addressbooks.id', $this->teamAddressBook['id'])
                ->where('ViewEmail', $this->userPublicIdToDelete)
                ->select('adav_cards.uri as card_uri', 'adav_addressbooks.id as addressbook_id')
                ->first();

            if ($card) {
                Backend::Carddav()->deleteCard($card->addressbook_id, $card->card_uri);
            }
        }
    }

    public function onAfterCheckAccessToAddressBook(&$aArgs, &$mResult)
    {
        if (isset($aArgs['User'], $aArgs['AddressBookId'])) {
            $addressbook = $this->GetTeamAddressbook($aArgs['User']->Id);
            if ($addressbook && $addressbook['id'] == $aArgs['AddressBookId']) {
                $mResult = true;

                return $mResult;
            }
        }
    }

    public function onBeforeUpdateContactObject(&$aArgs, &$mResult)
    {
        $user = Api::getAuthenticatedUser();

        if ($user && isset($aArgs['Contact'])) {
            $addressbook = Backend::Carddav()->getAddressBookById($aArgs['Contact']->Storage);
            if ($addressbook['uri'] === 'gab') {
                $teamAddressbook = $this->GetTeamAddressbook($user->Id);
                if ($user->Role === \Aurora\System\Enums\UserRole::SuperAdmin ||
                    ($user->Role === \Aurora\System\Enums\UserRole::TenantAdmin && $teamAddressbook['id'] === (int) $aArgs['Contact']->Storage) ||
                    (isset($aArgs['Contact']->ExtendedInformation['ItsMe']) && $aArgs['Contact']->ExtendedInformation['ItsMe'])) {

                } else {
                    throw new ApiException(\Aurora\System\Notifications::AccessDenied, null, 'AccessDenied');
                }
            }
        }
    }
}
