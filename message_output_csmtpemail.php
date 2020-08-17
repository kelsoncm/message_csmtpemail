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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Contains the definiton of the email message processors (sends messages to users via email)
 *
 * @package   message_csmtpemail
 * @copyright 2008 Luis Rodrigues and Martin Dougiamas
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot.'/message/output/lib.php');

/**
 * The email message processor
 *
 * @package   message_csmtpemail
 * @copyright 2008 Luis Rodrigues and Martin Dougiamas
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message_output_csmtpemail extends message_output {
    /**
     * Processes the message (sends by email).
     * @param object $eventdata the event data submitted by the message sender plus $eventdata->savedmessageid
     */
    function send_message($eventdata) {
        global $CFG;

        // skip any messaging suspended and deleted users
        if ($eventdata->userto->auth === 'nologin' or $eventdata->userto->suspended or $eventdata->userto->deleted) {
            return true;
        }

        //the user the email is going to
        $recipient = null;

        //check if the recipient has a different email address specified in their messaging preferences Vs their user profile
        $emailmessagingpreference = get_user_preferences('message_processor_email_csmtpemail', null, $eventdata->userto);
        $emailmessagingpreference = clean_param($emailmessagingpreference, PARAM_EMAIL);

        // If the recipient has set an email address in their preferences use that instead of the one in their profile
        // but only if overriding the notification email address is allowed
        if (!empty($emailmessagingpreference) && !empty($CFG->messagingallowemailoverride)) {
            //clone to avoid altering the actual user object
            $recipient = clone($eventdata->userto);
            $recipient->email = $emailmessagingpreference;
        } else {
            $recipient = $eventdata->userto;
        }

        // Check if we have attachments to send.
        $attachment = '';
        $attachname = '';
        if (!empty($CFG->allowattachments) && !empty($eventdata->attachment)) {
            if (empty($eventdata->attachname)) {
                // Attachment needs a file name.
                debugging('Attachments should have a file name. No attachments have been sent.', DEBUG_DEVELOPER);
            } else if (!($eventdata->attachment instanceof stored_file)) {
                // Attachment should be of a type stored_file.
                debugging('Attachments should be of type stored_file. No attachments have been sent.', DEBUG_DEVELOPER);
            } else {
                // Copy attachment file to a temporary directory and get the file path.
                $attachment = $eventdata->attachment->copy_content_to_temp();

                // Get attachment file name.
                $attachname = clean_filename($eventdata->attachname);
            }
        }

        // Configure mail replies - this is used for incoming mail replies.
        $replyto = '';
        $replytoname = '';
        if (isset($eventdata->replyto)) {
            $replyto = $eventdata->replyto;
            if (isset($eventdata->replytoname)) {
                $replytoname = $eventdata->replytoname;
            }
        }
        $account = get_config('message_custonsmtp_email', 'mmsmtp');
        if($account){
            require_once($CFG->dirroot.'/local/custonsmtp/locallib.php');
            $account = $DB->get_record('custonsmtp_accounts',array('id'=>$account));
            $mailOb = new stdClass();
            $mailOb->to_adress = $recipient->email;
            $mailOb->from_mail = $account->username;
            $mailOb->from_name = $eventdata->userfrom->firstname;
            $mailOb->title = $eventdata->subject;
            $mailOb->body = $eventdata->fullmessage;
            $mailOb->bodyHTML = $eventdata->fullmessagehtml;
            $mailOb->bodyHTML = $eventdata->fullmessagehtml;
            $mailOb->replyto = $replyto;
            SendMailToQueue($mailOb);
            $result = email_to_user($recipient, $eventdata->userfrom, $eventdata->subject, $eventdata->fullmessage,
                                $eventdata->fullmessagehtml, $attachment, $attachname, true, $replyto, $replytoname);
        }
        else{
            $result = email_to_user($recipient, $eventdata->userfrom, $eventdata->subject, $eventdata->fullmessage,
                                $eventdata->fullmessagehtml, $attachment, $attachname, true, $replyto, $replytoname);
        }

        // Remove an attachment file if any.
        if (!empty($attachment) && file_exists($attachment)) {
            unlink($attachment);
        }

        return $result;
    }

    /**
     * Creates necessary fields in the messaging config form.
     *
     * @param array $preferences An array of user preferences
     */
    function config_form($preferences){
        global $USER, $OUTPUT, $CFG;
        $string = '';

        $choices = array();
        $choices['0'] = get_string('textformat');
        $choices['1'] = get_string('htmlformat');
        $current = $preferences->mailformat;
        $string .= $OUTPUT->container(html_writer::label(get_string('emailformat'), 'mailformat'));
        $string .= $OUTPUT->container(html_writer::select($choices, 'mailformat', $current, false, array('id' => 'mailformat')));

        if (!empty($CFG->allowusermailcharset)) {
            $choices = array();
            $charsets = get_list_of_charsets();
            if (!empty($CFG->sitemailcharset)) {
                $choices['0'] = get_string('site').' ('.$CFG->sitemailcharset.')';
            } else {
                $choices['0'] = get_string('site').' (UTF-8)';
            }
            $choices = array_merge($choices, $charsets);
            $current = $preferences->mailcharset;
            $string .= $OUTPUT->container(html_writer::label(get_string('emailcharset'), 'mailcharset'));
            $string .= $OUTPUT->container(
                html_writer::select($choices, 'preference_mailcharset', $current, false, array('id' => 'mailcharset'))
            );
        }

        if (!empty($CFG->messagingallowemailoverride)) {
            $inputattributes = array('size' => '30', 'name' => 'email_csmtp', 'value' => $preferences->email_csmtp,
                    'id' => 'email_csmtp');
            $string .= html_writer::label(get_string('email', 'message_csmtp'), 'email_csmtp');
            $string .= $OUTPUT->container(html_writer::empty_tag('input', $inputattributes));

            if (empty($preferences->email_csmtp) && !empty($preferences->userdefaultemail)) {
                $string .= $OUTPUT->container(get_string('ifemailleftempty', 'email_csmtp', $preferences->userdefaultemail));
            }

            if (!empty($preferences->email_csmtp) && !validate_email($preferences->email_csmtp)) {
                $string .= $OUTPUT->container(get_string('invalidemail'), 'error');
            }

            $string .= '<br/>';
        }

        return $string;
    }

    /**
     * Parses the submitted form data and saves it into preferences array.
     *
     * @param stdClass $form preferences form class
     * @param array $preferences preferences array
     */
    function process_form($form, &$preferences){
        if (isset($form->email_csmtp)) {
            $preferences['message_processor_email_csmtp'] = $form->email_csmtp;
        }
        if (isset($form->preference_mailcharset)) {
            $preferences['mailcharset'] = $form->preference_mailcharset;
        }
    }

    /**
     * Returns the default message output settings for this output
     *
     * @return int The default settings
     */
    public function get_default_messaging_settings() {
        return MESSAGE_PERMITTED + MESSAGE_DEFAULT_LOGGEDIN + MESSAGE_DEFAULT_LOGGEDOFF;
    }

    /**
     * Loads the config data from database to put on the form during initial form display
     *
     * @param array $preferences preferences array
     * @param int $userid the user id
     */
    function load_data(&$preferences, $userid){
        $preferences->email_csmtp = get_user_preferences( 'message_processor_email_csmtp', '', $userid);
    }

    /**
     * Returns true as message can be sent to internal support user.
     *
     * @return bool
     */
    public function can_send_to_any_users() {
        return true;
    }
}