<?php

namespace Drupal\campaignion_expiry;

use Drupal\campaignion\Contact;

/**
 * Cron-job for expiring old contact records.
 */
class ContactCron {

  /**
   * Create a new cron-job instance.
   *
   * @param int $time_limit
   *   Soft time limit (in seconds): No new batches are started in the same
   *   cron-run after the limit has been reached.
   * @param string $inactive_since_str
   *   String that can be passed to strtotime(). Expire all contacts that didn’t
   *   have any activity logged in this time frame.
   * @param bool $keep_mp_fields
   *   If TRUE the MP data is copied to the anonymized contacts, otherwise it is
   *   deleted.
   * @param bool $exclude_opted_in
   *   If TRUE contacts with any type of active opt-in are exempt from the data
   *   expiry.
   */
  public function __construct(int $time_limit, string $inactive_since_str, bool $keep_mp_fields = FALSE, bool $leave_opted_in = FALSE) {
    $this->timeLimit = $time_limit;
    $this->inactiveSinceStr = $inactive_since_str;
    $this->keepMpFields = $keep_mp_fields;
    $this->excludeOptedIn = $exclude_opted_in;
    $this->entityController = entity_get_controller('redhen_contact');
  }

  /**
   * Create a new anonymous contact and copy over some data we want to keep.
   *
   * @param \Drupal\campaignion\Contact $contact
   *   Contact that is being anonymized.
   */
  protected function anonymize(Contact $contact) {
    $new_contact = new Contact();
    $new_contact->contact_id = $contact->contact_id;
    $new_contact->redhen_state = REDHEN_STATE_ARCHIVED;
    $new_contact->setEmail("{$contact->contact_id}@deleted");

    $new_contact->first_name = 'Anonymous';
    $new_contact->last_name = $contact->contact_id;

    // Copy first country of $contact to $new_contact.
    foreach ($contact->wrap()->field_address->value() as $item) {
      if (!empty($item['country'])) {
        $new_contact->wrap()->field_address->set([['country' => $item['country']]]);
        break;
      }
    }

    // Copy tags of $contact to $new_contact.
    $new_contact->supporter_tags = $contact->supporter_tags;
    $new_contact->campaign_tag = $contact->campaign_tag;
    $new_contact->source_tag = $contact->source_tag;

    // Copy MP fields of $contact to $new_contact.
    if ($this->keepMpFields) {
      $new_contact->mp_constituency = $contact->mp_constituency;
      $new_contact->mp_country = $contact->mp_country;
      $new_contact->mp_party = $contact->mp_party;
      $new_contact->mp_salutation = $contact->mp_salutation;
    }

    $new_contact->created = $contact->created;
    $new_contact->log = "Contact not being updated since “{$this->inactiveSinceStr}” and has been anonymized";
    $new_contact->save();

    $this->entityController->resetCache([$contact->contact_id]);
  }

  /**
   * Delete all but the last revision of a contact.
   *
   * @param \Drupal\campaignion\Contact $contact
   *   The contact which’s revisions should be removed.
   */
  protected function deleteOldRevisions(Contact $contact) {
    $sql_revisions = <<<SQL
SELECT revision_id
FROM redhen_contact_revision
WHERE contact_id=:current_contact_id
ORDER BY revision_id;
SQL;

    $current_contact_id = $contact->contact_id;
    $revisions = db_query($sql_revisions, [':current_contact_id' => $current_contact_id])->fetchCol();
    $last_revision = array_pop($revisions);
    foreach ($revisions as $revision) {
      entity_revision_delete('redhen_contact', $revision);
    }
  }

  /**
   * Load inactive contacts from the database.
   */
  protected function loadInactiveContacts(int $inactive_since, int $last_id = 0, int $limit = 20) {

    if ($this->excludeOptedIn) {
      $sql = <<<SQL
SELECT c.contact_id
FROM redhen_contact c
  INNER JOIN field_data_redhen_contact_email AS ce ON ce.entity_type='redhen_contact' AND ce.entity_id=contact_id
  LEFT OUTER JOIN (
    campaignion_activity ca
    INNER JOIN campaignion_activity_webform USING(activity_id)
    ) ON ca.contact_id=c.contact_id AND ca.created>:time
    LEFT OUTER JOIN field_data_field_opt_in_post AS post ON post.entity_type='redhen_contact' AND post.entity_id=c.contact_id AND post.field_opt_in_post_value=1
  LEFT OUTER JOIN field_data_field_opt_in_phone AS phone ON phone.entity_type='redhen_contact' AND phone.entity_id=c.contact_id AND phone.field_opt_in_phone_value=1
  LEFT OUTER JOIN campaignion_newsletters_subscriptions AS nl ON redhen_contact_email_value=email
WHERE redhen_contact_email_value NOT LIKE '%@deleted'
AND ca.activity_id IS NULL
AND post.field_opt_in_post_value IS NULL
AND phone.field_opt_in_phone_value IS NULL
AND email IS NULL
AND c.updated < :time
AND c.contact_id > :last_id
ORDER BY c.contact_id
LIMIT $limit;
SQL;
    }
    else {
      $sql = <<<SQL
SELECT c.contact_id
FROM redhen_contact c
  INNER JOIN field_data_redhen_contact_email AS ce ON ce.entity_type='redhen_contact' AND ce.entity_id=contact_id
   LEFT OUTER JOIN (
    campaignion_activity ca
    INNER JOIN campaignion_activity_webform USING(activity_id)
  ) ON ca.contact_id=c.contact_id AND ca.created>:time
WHERE redhen_contact_email_value NOT LIKE '%@deleted'
AND ca.activity_id IS NULL
AND c.updated < :time
AND c.contact_id > :last_id
ORDER BY c.contact_id
LIMIT $limit;
SQL;
    }
    $params = [':last_id' => $last_id, ':time' => $inactive_since];
    if ($ids = db_query($sql, $params)->fetchCol()) {
      return entity_load('redhen_contact', $ids, [], TRUE);
    }
    return FALSE;
  }

  /**
   * Execute the cron-job.
   */
  public function run() {
    $stop_after = time() + $this->timeLimit;
    $last_id = 0;
    $contact_count = 0;
    $inactive_since = strtotime($this->inactiveSinceStr);
    $args['@time'] = format_date($inactive_since);
    watchdog('campaignion_expiry', 'Expiring contacts inactive since @time', $args, WATCHDOG_INFO);
    while (time() < $stop_after && ($contacts = $this->loadInactiveContacts($inactive_since, $last_id))) {
      foreach ($contacts as $contact) {
        $this->anonymize($contact);
        $this->deleteOldRevisions($contact);
        $last_id = $contact->contact_id;
        $contact_count++;
      }
    }
    $args['@count'] = $contact_count;
    watchdog('campaignion_expiry', 'Expired @count contacts.', $args, WATCHDOG_INFO);
  }

}
