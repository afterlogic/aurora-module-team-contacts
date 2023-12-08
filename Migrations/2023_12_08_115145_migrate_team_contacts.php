<?php

use Afterlogic\DAV\Backend;
use Aurora\Modules\Contacts\Enums\StorageType;
use Aurora\Modules\Contacts\Models\Contact;
use Aurora\Modules\Contacts\Models\ContactCard;
use Aurora\Modules\Core\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Capsule\Manager as Capsule;
use Sabre\DAV\UUIDUtil;

class MigrateTeamContacts extends Migration
{
    protected function getTeamAddressBook($tenantId, $createIfNotExists = true)
    {
        $sPrincipalUri = \Afterlogic\DAV\Constants::PRINCIPALS_PREFIX . $tenantId . '_' . \Afterlogic\DAV\Constants::DAV_TENANT_PRINCIPAL;
        $addressbook = Backend::Carddav()->getAddressBookForUser($sPrincipalUri, 'gab');
        if (!$addressbook && $createIfNotExists) {
            if (Backend::Carddav()->createAddressBook($sPrincipalUri, 'gab', ['{DAV:}displayname' => \Afterlogic\DAV\Constants::ADDRESSBOOK_TEAM_DISPLAY_NAME])) {
                $addressbook = Backend::Carddav()->getAddressBookForUser($sPrincipalUri, 'gab');
            }
        }

        return $addressbook;
    }

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Tenant::orderBy('Id')->chunk(100000, function ($tenants) {
            foreach ($tenants as $tenant) {
                
                $addressbook = $this->getTeamAddressBook($tenant->Id);

                if ($addressbook) {
                    Contact::where('Storage', StorageType::Team)
                        ->where('IdTenant', $tenant->Id)
                        ->orderBy('Id')->chunk(100000, function ($rows) use ($addressbook) {
                        foreach ($rows as $row) {
                            try {
                                $conactCard = ContactCard::where('AddressBookId', $addressbook['id'])->where('ViewEmail', $row->ViewEmail)->first();
                                if (!$conactCard) {
                                    $uid = UUIDUtil::getUUID();
                                    $vcard = new \Sabre\VObject\Component\VCard(['UID' => $uid]);
                                    $vcard->add(
                                        'EMAIL',
                                        $row->ViewEmail,
                                        [
                                            'type' => ['work'],
                                            'pref' => 1,
                                        ]
                                    );
                                    Backend::Carddav()->createCard($addressbook['id'], $uid . '.vcf', $vcard->serialize());
                                } else {
                                    \Aurora\System\Api::Log('Team contact already exists: ' . $row->ViewEmail, \Aurora\System\Enums\LogLevel::Error, 'contacts-migration-');
                                }
                            } catch (\Exception $e) {
                                \Aurora\System\Api::Log('Contact migration exception', \Aurora\System\Enums\LogLevel::Error, 'contacts-migration-');
                                \Aurora\System\Api::LogObject($row, \Aurora\System\Enums\LogLevel::Error, 'contacts-migration-');
                                \Aurora\System\Api::Log($e->getMessage(), \Aurora\System\Enums\LogLevel::Error, 'contacts-migration-');
                            }
                        }
                    });
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() 
    {
        Tenant::orderBy('Id')->chunk(100000, function ($tenants) {
            foreach ($tenants as $tenant) {
                $addressbook = $this->getTeamAddressBook($tenant->Id);
                if ($addressbook) {
                    Backend::Carddav()->deleteAddressBook($addressbook['id']);
                }
            }
        });
    }
}
