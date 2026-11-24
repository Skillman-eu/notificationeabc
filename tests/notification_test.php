<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

namespace enrol_notificationeabc;

defined('MOODLE_INTERNAL') || die();

/**
 * Notification compatibility regression tests.
 *
 * @package enrol_notificationeabc
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notification_test extends \advanced_testcase {
    /** New instances must have non-null editor, sender and checkbox defaults. */
    public function test_instance_defaults_without_configuration(): void {
        $this->resetAfterTest();
        foreach (['location', 'unenrolmessage', 'updatedenrolmessage', 'activeenrolalert',
                'activeunenrolalert', 'activeenrolupdatedalert', 'emailsender', 'namesender', 'emailreceiver'] as $setting) {
            unset_config($setting, 'enrol_notificationeabc');
        }
        $plugin = enrol_get_plugin('notificationeabc');
        $defaults = $plugin->get_instance_defaults();
        foreach (['customtext1', 'customtext2', 'customtext3', 'customchar1', 'customchar2', 'customchar3'] as $field) {
            $this->assertSame('', $defaults[$field]);
        }
        foreach (['customint1', 'customint2', 'customint3', 'customint4', 'customint5'] as $field) {
            $this->assertSame(0, $defaults[$field]);
        }
    }

    /** Configuration continues to populate defaults after normalizing absent settings. */
    public function test_configured_instance_defaults(): void {
        $this->resetAfterTest();
        $settings = ['location' => 'customtext1', 'unenrolmessage' => 'customtext2',
            'updatedenrolmessage' => 'customtext3', 'emailsender' => 'customchar1',
            'namesender' => 'customchar2', 'emailreceiver' => 'customchar3'];
        foreach ($settings as $setting => $field) {
            set_config($setting, 'Configured ' . $field, 'enrol_notificationeabc');
        }
        $defaults = enrol_get_plugin('notificationeabc')->get_instance_defaults();
        foreach ($settings as $field) {
            $this->assertSame('Configured ' . $field, $defaults[$field]);
        }
    }

    /** A normal submission omits legacy controls; saving it must not read absent properties. */
    public function test_instance_submission_without_legacy_controls(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $plugin = enrol_get_plugin('notificationeabc');
        $data = (object)[
            'status' => ENROL_INSTANCE_ENABLED,
            'name' => 'Notifications',
            'customint1' => 1,
            'customint2' => 0,
            'customint3' => 1,
            'customtext1' => ['text' => '<p>Hello {NOMBRE}</p>', 'format' => FORMAT_HTML],
            'customtext2' => ['text' => 'Goodbye', 'format' => FORMAT_HTML],
            'customtext3' => ['text' => 'Updated', 'format' => FORMAT_HTML],
            'customchar1' => 'sender@example.org',
            'customchar2' => 'Course team',
            'customchar3' => 'staff@example.org',
        ];
        $fields = $plugin->get_instance_fields($data);
        $this->assertSame(0, $fields['customint4']);
        $this->assertSame(0, $fields['customint5']);
        $this->assertSame(1, $fields['customint1']);
        $this->assertSame(0, $fields['customint2']);
        $this->assertSame(1, $fields['customint3']);
        $this->assertFalse(property_exists($data, 'customint4'));
        $course = $this->getDataGenerator()->create_course();
        $id = $plugin->add_instance($course, $fields);
        $instance = $DB->get_record('enrol', ['id' => $id], '*', MUST_EXIST);
        foreach ($fields as $field => $value) {
            $this->assertEquals($value, $instance->{$field});
        }

        // The edit path uses the same conversion for existing instances.
        $data->name = 'Updated notifications';
        $data->customint1 = 0;
        $data->customtext1['text'] = '<p>Changed template</p>';
        foreach ($plugin->get_instance_fields($data) as $field => $value) {
            $instance->{$field} = $value;
        }
        $DB->update_record('enrol', $instance);
        $instance = $DB->get_record('enrol', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('Updated notifications', $instance->name);
        $this->assertEquals(0, $instance->customint1);
        $this->assertSame('<p>Changed template</p>', $instance->customtext1);
    }

    /** Unchecked and empty optional controls must have safe database values. */
    public function test_empty_optional_instance_fields(): void {
        $fields = enrol_get_plugin('notificationeabc')->get_instance_fields((object)[
            'status' => ENROL_INSTANCE_ENABLED, 'name' => 'Notifications',
        ]);
        for ($i = 1; $i <= 5; $i++) {
            $this->assertSame(0, $fields['customint' . $i]);
        }
        for ($i = 1; $i <= 3; $i++) {
            $this->assertSame('', $fields['customtext' . $i]);
            $this->assertSame('', $fields['customchar' . $i]);
        }
    }

    /** Invalid types must fail explicitly instead of using an undefined message body. */
    public function test_invalid_notification_type(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->expectException(\invalid_parameter_exception::class);
        enrol_get_plugin('notificationeabc')->enviarmail($user, $course, 99);
    }

    /** Unconfigured templates must produce nonempty fallback notifications for both recipients. */
    public function test_unconfigured_templates_send_notifications(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        foreach (['location', 'unenrolmessage', 'updatedenrolmessage'] as $setting) {
            unset_config($setting, 'enrol_notificationeabc');
        }
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $receiver = $this->getDataGenerator()->create_user();
        set_config('emailreceiver', $receiver->email, 'enrol_notificationeabc');
        $plugin = enrol_get_plugin('notificationeabc');
        $sink = $this->redirectMessages();

        foreach ([1 => 'filelockedmail', 2 => 'unenrolmessagedefault', 3 => 'updatedenrolmessagedefault'] as $type => $key) {
            $this->assertTrue($plugin->enviarmail($user, $course, $type));
            $messages = $sink->get_messages();
            $this->assertCount(2, $messages);
            $this->assertEquals($user->id, $messages[0]->useridto);
            $this->assertEquals($receiver->id, $messages[1]->useridto);
            foreach ($messages as $message) {
                $this->assertEquals(1, $message->notification);
                $this->assertSame(get_string($key, 'enrol_notificationeabc', $course), $message->fullmessagehtml);
                $this->assertNotEmpty($message->fullmessage);
                $this->assertTrue($DB->record_exists('notifications', ['id' => $message->id]));
            }
            $sink->clear();
        }
        $sink->close();
    }
}
