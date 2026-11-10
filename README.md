# HackerOne Nextcloud Talk bot

A bot that posts HackerOne reports into a Nextcloud Talk chat room.

## Setup

1. Create the conversation for your security team
2. Deploy `hackerone.php` **inside** the webroot on a server
3. Deploy `hackerone.sample.json` renamed to `hackerone.json` **outside** of the webroot on a server
    - Use the parent directory or
    - Adjust the line `$configData = file_get_contents('../hackerone.json');` in `hackerone.php`
4. Populate `hackerone.json`:
   1. Generate a 64 character long secrets and store as:`nextcloud-secret`
   2. Generate a 64 character long **different** secrets and store as:`hackerone-secret`
   3. Add your Nextcloud server URL as `server`
   4. Add your conversation token from step 1. as `conversation`
   5. Add the public link of the triage calendar as `triage-calendar` (see below)

5. Navigate to your HackerOne program webhooks: https://hackerone.com/nextcloud/webhooks
6. Configure a webhook:
   1. Webhook name: Talk bot
   2. Payload URL: Pointing to the `hackerone.php` from step 2.
   3. Secret: `hackerone-secret` from step 4.2
   4. Which events should trigger this webhook? - Select:
      - Report created
      - Report new

7. Install the bot:
   ```shell
   occ talk:bot:install \
       --no-setup \
       --feature=response \
       'HackerOne' \
       '<nextcloud-secret from step 4.1>' \
       '<Payloard URL from step 5.2>'
   ```

8. Find out the bot ID:
   ```shell
   occ talk:bot:list
   ```

9. Configure the bot for your conversation from step 1.:
   ```shell
   occ talk:bot:setup \
       '<id from step 8.>' \
       '<token from step 4.4>'
   ```
   

## Triage calendar

The bot mentions the person on triage, read from a shared Nextcloud calendar:

1. Create a calendar and share it with edit rights with the triagers
2. Enable "Share link", copy the subscription link and add it as `https://` link **without** `?export` as `triage-calendar` in `hackerone.json`:
   `https://<server>/remote.php/dav/public-calendars/<token>`
3. Triagers sign up with an all-day event whose title is their Nextcloud user ID (lowercase a-z only), with visibility "Show full event"

If nobody signed up for the day, or the calendar can't be read (e.g. the link still contains `?export`), the bot mentions `@all`.
