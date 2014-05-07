# Akamai Cache Clearer

This is a PHP script that uses the GitHub API and the Akamai CCUAPI to clear items from the HTTP cache for the Akamai CDN network.

You provide it with the tags that describe the old release tag and the new release tag, it logs into the GitHub API to get the differences, and clears out the items that match a set of paths provided in the configuration.

## Configuration
You will need a GitHub oAuth access token (how to get one of these isn't covered here), the owner and repo name of the repo (for example for this repo the owner is me, `lilmuckers` and the repo is `akamai-cache-clear`.

You will need a login for the Akamai Luna control panel, with permissions to use the **Content Control Utility** to clear items from the cache.

Options are specified in a key/value way, as below. For all options; see the akamai [documentation](http://drupal.org/files/issues/Content_Control_Interfaces.pdf).

Paths have a key of the base path in your repo, and a value of the equivilent Akamai base path.
```ini
[github]
access_token = 
owner = 
repo = 

[akamai]
username = 
password = 
options[action] = remove
options[type] = arl
paths[images] = http://a9.g.akamai.net/7/2/3/4/www.example.com/images/
```

## Usage
This script is run with the following command:
```bash
 $ php akamai.php --old=v1.1.2 --new=v1.1.3
```
