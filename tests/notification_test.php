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
