# do-upgrade-plans
Upgrade your DigitalOcean droplets to better plans with the same cost.

This script will use the DigitalOcean API to upgrade all of your droplets to better plans with the same cost if available.

## Background

January 16, 2018 DigitalOcean released [new pricing plans] where they basically doubled the RAM for the same price of the old plans. But to get the benefits for your existing droplets, you have to upgrade all of your existing droplets in a process that involves shutting them down, selecting the new plan, waiting for the upgrade to happen and power on the droplets again. I have tens of droplets and had no intention of doing this manually, so I wrote a script to use the DigitalOcean API to automate the upgrades. 

## Installation

`composer create-project bjornjohansen/do-upgrade-plans`

## Run the upgrades

First you need a Personal Access Token with read and write access, which you can generate on the [Applications & API page](https://cloud.digitalocean.com/settings/api/tokens).

Copy your access token and set it as an environment variable: `export DO_ACCESS_TOKEN=<YOUR_64_CHAR_TOKEN_HERE>`.

Run the script: `php -f do-upgrade-plans/upgrade-plans.php`

Watch as your droplets get upgraded.

In case an error happens, your droplets will error cycle. This happened to 3 of my droplets. Further investigation (trying to upgrade manually) revealed that “Due to high demand and capacity restrictions we have temporarily disabled this size in this region.”
