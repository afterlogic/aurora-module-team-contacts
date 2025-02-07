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
use Aurora\Modules\Contacts\Models\ContactCard;
use Aurora\Modules\Contacts\Module as ContactsModule;
use Aurora\System\Enums\UserRole;
use Sabre\VObject\UUIDUtil;
use Illuminate\Database\Capsule\Manager as Capsule;
use Aurora\System\Exceptions\ApiException;
use Aurora\Modules\Contacts\Enums\Access;

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

    protected $storagesMapToAddressbooks = [
        StorageType::Team => Constants::ADDRESSBOOK_TEAM_NAME
    ];

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
        $this->subscribeEvent('Contacts::UpdateAddressBook::before', array($this, 'onBeforeUpdateAddressBook'));

        $this->subscribeEvent('Contacts::PopulateContactArguments', array($this, 'populateContactArguments'));
        $this->subscribeEvent('Contacts::CreateContact::before', array($this, 'populateContactArguments'));
        $this->subscribeEvent('Contacts::ContactQueryBuilder', array($this, 'onContactQueryBuilder'));

        $this->subscribeEvent('Core::DeleteUser::before', array($this, 'onBeforeDeleteUser'));
        $this->subscribeEvent('Core::DeleteUser::after', array($this, 'onAfterDeleteUser'));

        $this->subscribeEvent('Contacts::UpdateContactObject::before', array($this, 'onBeforeUpdateContactObject'));
        $this->subscribeEvent('Contacts::GetStoragesMapToAddressbooks::after', array($this, 'onAfterGetStoragesMapToAddressbooks'));

        $this->subscribeEvent('Core::GetGroupContactsEmails', array($this, 'onGetGroupContactsEmails'));
        $this->subscribeEvent('Contacts::getContactsCollection', array($this, 'onGetContactsCollection'));
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
                'EntityId' => (int) $addressbook['id'],
                'CTag' => (int) $addressbook['{http://sabredav.org/ns}sync-token'],
                'Display' => true,
                'Order' => 1,
                'DisplayName' => $addressbook['{DAV:}displayname'],
                'Uri' => $addressbook['uri'],
                'Url' => $addressbook['uri'],
            ];
        }
    }

    public function GetTeamAddressbook($UserId)
    {
        Api::CheckAccess($UserId);

        $addressbook = false;

        $oUser = Api::getUserById($UserId);
        if ($oUser) {
            $sPrincipalUri = Constants::PRINCIPALS_PREFIX . $oUser->IdTenant . '_' . Constants::DAV_TENANT_PRINCIPAL;
            $addressbook = Backend::Carddav()->getAddressBookForUser($sPrincipalUri, 'gab');
            if (!$addressbook) {
                if (Backend::Carddav()->createAddressBook($sPrincipalUri, 'gab', ['{DAV:}displayname' => Constants::ADDRESSBOOK_TEAM_DISPLAY_NAME])) {
                    $addressbook = Backend::Carddav()->getAddressBookForUser($sPrincipalUri, 'gab');
                }
            }
            if ($addressbook) {
                $addressbook['id_tenant'] = $oUser->IdTenant;
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

        if ((int) $iUserId > 0) {
            $this->createContactForUser($iUserId, $aArgs['PublicId']);
        }
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

                    $aArgs['Query']->addSelect('cu.Id as TeamUserId');
                    $aArgs['Query']->leftJoin('core_users as cu', 'contacts_cards.BusinessEmail', '=', "cu.PublicId");
                }
                $mResult = $mResult->orWhere('adav_cards.addressbookid', $addressbook['id']);
            }
        }
    }

    public function onAfterGetContacts($aArgs, &$mResult)
    {
        if (\is_array($mResult) && \is_array($mResult['List'])) {
            $user = Api::getUserById($aArgs['UserId']);
            $teamAddressbook = $this->GetTeamAddressbook($aArgs['UserId']);

            if ($user && $teamAddressbook) {
                $authenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
                foreach ($mResult['List'] as $iIndex => $aContact) {
                    $allowEditTeamContactsByTenantAdmins = ContactsModule::getInstance()->oModuleSettings->AllowEditTeamContactsByTenantAdmins;
                    $isUserTenantAdmin = $authenticatedUser->Role === UserRole::TenantAdmin && $user->IdTenant === $authenticatedUser->IdTenant;

                    if (isset($aContact['AddressBookId']) && $aContact['AddressBookId'] == $teamAddressbook['id']) {
                        $aContact['ReadOnly'] = false;
                        if ($aContact['ViewEmail'] === $user->PublicId) {
                            $aContact['ItsMe'] = true;
                        } elseif (!(($allowEditTeamContactsByTenantAdmins && $isUserTenantAdmin) || $authenticatedUser->isAdmin())) {
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
            if ($mResult && $authenticatedUser && $mResult->AddressBookId == $teamAddressbook['id']) {
                $mResult->IdTenant = $teamAddressbook['id_tenant'] ?? 0;
                $allowEditTeamContactsByTenantAdmins = ContactsModule::getInstance()->oModuleSettings->AllowEditTeamContactsByTenantAdmins;
                $isUserTenantAdmin = $authenticatedUser->Role === UserRole::TenantAdmin;
                $isContactInTenant = $mResult->IdTenant === $authenticatedUser->IdTenant;
                if ($mResult->BusinessEmail === $authenticatedUser->PublicId) {
                    $mResult->ExtendedInformation['ItsMe'] = true;
                } elseif (!(($allowEditTeamContactsByTenantAdmins && $isUserTenantAdmin && $isContactInTenant) || $authenticatedUser->isAdmin())) {
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
        if ($oUser && $oUser->Role === UserRole::NormalUser) {
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
    public function populateContactArguments(&$aArgs, &$mResult)
    {
        if (isset($aArgs['Storage'], $aArgs['UserId'])) {
            $aStorageParts = \explode('-', $aArgs['Storage']);
            if (isset($aStorageParts[0]) && $aStorageParts[0] === StorageType::Team) {

                $addressbook = $this->GetTeamAddressbook($aArgs['UserId']);
                if ($addressbook) {
                    $aArgs['Storage'] = StorageType::Team;
                    $aArgs['AddressBookId'] = $addressbook['id'];

                    $mResult = true;
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
                $ids = $aArgs['UUID'];
                if (count($aArgs['UUID']) === 0) {
                    $ids = [null];
                }
                $q->whereIn('adav_cards.id', $ids);
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
            $oUser = $aArgs['User'];
            $addressbook = $this->GetTeamAddressbook($oUser->Id);
            if ($addressbook && $addressbook['id'] == $aArgs['AddressBookId']) {
                if ($aArgs['Access'] === Access::Write && $oUser->UserRole !== UserRole::SuperAdmin) {
                    if ($oUser->UserRole === UserRole::TenantAdmin && ContactsModule::getInstance()->oModuleSettings->AllowEditTeamContactsByTenantAdmins) {
                        $mResult = true;
                    } else {
                        $mResult = false;
                    }
                } else {
                    $mResult = true;
                }
                return true;
            }
        }
    }

    public function onAfterCheckAccessToObject(&$aArgs, &$mResult)
    {
        $oUser = $aArgs['User'] ?? null;
        $oContact = $aArgs['Contact'] ?? null;

        if ($oUser) {
            $teamAddressBook = $this->GetTeamAddressbook($oUser->Id);
            if ($oContact instanceof \Aurora\Modules\Contacts\Classes\Contact && (int) $oContact->AddressBookId === (int) $teamAddressBook['id']) {
                if ($aArgs['Access'] === Access::Write && $aArgs['User']->UserRole !== UserRole::SuperAdmin) {
                    if ((isset($oContact->ExtendedInformation['ItsMe']) && $oContact->ExtendedInformation['ItsMe']) || // ItsMe
                        ($oUser->Role === UserRole::TenantAdmin && ContactsModule::getInstance()->oModuleSettings->AllowEditTeamContactsByTenantAdmins)) {
                        $mResult = true;
                    } else {
                        $mResult = false;
                    }
                } else { // is SuperAdmin
                    $mResult = true;
                }
                return true;
            }
        }
    }

    public function onBeforeUpdateContactObject(&$aArgs, &$mResult)
    {
        $user = Api::getAuthenticatedUser();
        $oContact = $aArgs['Contact'] ?? null;

        if ($user && $oContact) {
            $addressbook = Backend::Carddav()->getAddressBookById($oContact->AddressBookId);
            if ($addressbook['uri'] === 'gab') {

                // no one can edit the BusinessEmail property because Contact has a relationship with User on this property,
                // so if the property was changed, then we return the previous value
                $oOldContact = ContactCard::firstWhere('CardId', $oContact->Id);
                if ($oOldContact && $oContact->BusinessEmail != $oOldContact->BusinessEmail) {
                    $oContact->BusinessEmail = $oOldContact->BusinessEmail;
                }
                $teamAddressbook = $this->GetTeamAddressbook($user->Id);

                $isSuperAdmin = $user->Role === UserRole::SuperAdmin;
                $isTenant = $user->Role === UserRole::TenantAdmin;
                $isCorrectTeamAddressbook = $teamAddressbook['id'] == $oContact->AddressBookId;
                $isItsMe = isset($oContact->ExtendedInformation['ItsMe']) && $oContact->ExtendedInformation['ItsMe'];
                $isReadOnly = isset($oContact->ExtendedInformation['ReadOnly']) && $oContact->ExtendedInformation['ReadOnly'];

                if (!($isSuperAdmin || ($isTenant && !$isReadOnly && $isCorrectTeamAddressbook) || $isItsMe)) {
                    throw new ApiException(\Aurora\System\Notifications::AccessDenied, null, 'AccessDenied');
                }
            }
        }
    }

    public function onBeforeUpdateAddressBook(&$aArgs, &$mResult)
    {
        $addressbook = Backend::Carddav()->getAddressBookById($aArgs['EntityId']);
        if ($addressbook && $addressbook['uri'] === 'gab') {
            throw new ApiException(\Aurora\System\Notifications::AccessDenied, null, 'AccessDenied');
        }
    }

    public function onAfterGetStoragesMapToAddressbooks(&$aArgs, &$mResult)
    {
        $mResult = array_merge($mResult, $this->storagesMapToAddressbooks);
    }

    public function onGetGroupContactsEmails(&$aArgs, &$mResult)
    {
        $oUser = $aArgs['User'];
        $oGroup = $aArgs['Group'];
        if ($oUser && $oGroup) {
            $abook = $this->GetTeamAddressbook($oUser->Id);
            if ($abook) {
                if ($oGroup->IsAll) {
                    $mResult = ContactCard::where('AddressBookId', $abook['id'])->get()->map(
                        function (ContactCard $oContact) {
                            if (!empty($oContact->FullName)) {
                                return '"' . $oContact->FullName . '"' . '<' . $oContact->ViewEmail . '>';
                            } else {
                                return $oContact->ViewEmail;
                            }
                        }
                    )->toArray();
                } else {
                    $mResult = $oGroup->Users->map(function ($oUser) use ($abook) {
                        $oContact = ContactCard::where('IdUser', $oUser->Id)->where('AddressBookId', $abook['id'])->first();
                        if ($oContact && !empty($oContact->FullName)) {
                            return '"' . $oContact->FullName . '"' . '<' . $oUser->PublicId . '>';
                        } else {
                            return $oUser->PublicId;
                        }
                    })->toArray();
                }
            }
        }
    }

    public function onGetContactsCollection(&$aArgs, &$mResult)
    {
        if ($mResult) {
            $mResult->each(function ($contact) {
                if ($contact->IsTeam && $contact->TeamUserId) {
                    $contact->UserId = $contact->TeamUserId;
                }
                unset($contact->TeamUserId);
            });
        }
    }
}
