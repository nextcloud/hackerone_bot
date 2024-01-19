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
   
