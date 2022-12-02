# Quicksilver Script - WPMU Database Clone

Pantheon Quicksilver script designed to be run when a database is cloned from live to a lower environment.

The script will update the `wp_blogs` and `wp_site` table to match the new environment's base url.

It will do a search-replace across all sites, replacing their individual URL with the new one.

## Installation

1. Copy the code to your site repo at `/private/scripts`
2. Run `composer install` inside the script folder to install its dependencies.
3. Update your `pantheon.yml` file to include a snippet like below. This will enable the quicksilver hook.

    ```yml
    workflows:
        clone_database:
            after:
            - type: webphp
                description: Convert blog urls
                script: private/scripts/wpmu-db-clone/wpmu-db-clone.php

    ```

4. Edit the `$domains` array in `wpmu-db-clone.php` to match your site's configuration.
