# export_ttrss
Exports articles from Tiny Tiny RSS (tt-rss) into a format that FreshRSS can
import

## What it does

export_ttrss exports all articles a specfic user has stored in TT-RSS to
JSON files which can be imported in FreshRSS. The import will preserve the
read/unread and starred status of your articles. It will create missing feeds
and categories on the fly.

## Before you start

You **must** be running FreshRSS with at least version 1.21.1-dev (edge)
or 1.21.1 for this to work. 1.21.0 will not work! Also you need to apply
PR [#5629](https://github.com/FreshRSS/FreshRSS/pull/5629) and
[#5638](https://github.com/FreshRSS/FreshRSS/pull/5638).
As long as the two PRs aren't merged into edge you will have to:

```
$ git clone git@github.com:FreshRSS/FreshRSS.git
$ cd FreshRSS
$ git fetch origin pull/5629/head:no-update-after-import
$ git checkout no-update-after-import
$ git fetch origin pull/5638/head:import-tt-rss-categories
$ git checkout import-tt-rss-categories
```
Run this code in your web server.

## How to use it

`git clone https://github.com/robertdahlem/export_ttrss` this repo to a
system that can reach the TT-RSS database. Typically you run it on the
system where the TT-RSS database is hosted.

Copy `export_ttrss.config.example` to `export_ttrss.config.php` and edit
the latter. Fill in the connection string, the database user and password.
Typically you will find this data in your TT-RSS installation in `config.php`
as `TTRSS_DB_*`.

Run `./export_ttrss.php --help`. Check all parameters.

Run `./export_ttrss.php --ttrss-user USERNAME` where USERNAME is the name
you use to login to TT-RSS. Add other parameters as you like. You will end
up with a bunch of files starting with `ttrss-USERNAME.00000001.json` and
increasing numbers.

The batch size delimits how much articles are put into a single file.
Remember that you have to import each of the files, so don't choose your
batch size too small. Unfortunately there is an individual limit for each
installation where the batch size gets to large and you will run into
"504 Gateway Time-out". You will need to experiment, but also see "php.ini"
a bit later in this document.

Now transport all .json files to the system where you use your browser to
access FreshRSS.

Look for the biggest file and check its file size. You need values bigger
than this in the following examples. Don't blindly copy my 100M! Size it
at least a bit above the size of your biggest .json file. Remember that these
values need to be adjusted on the FreshRSS system, not on the TT-RSS system!

### /etc/php/7.4/fpm/php.ini
(adjust file name according to your PHP version)

- `post_max_size = 100M`
- `upload_max_filesize = 100M`
- You can increase `max_execution_time` in case you run into "504 Gateway Time-out"

Don't forget to `systemctl restart php7.4-fpm`.

### Your relevant nginx configuration file
(maybe something like `/etc/nginx/sites-enabled/freshrss.conf`)

- `client_max_body_size 100M;`

Don't forget to `systemctl restart nginx`.

### Your relevant https configuration file
(maybe something like `/etc/apache2/sites-enabled/freshrss.conf`)

- `LimitRequestBody 104857600`

Don't forget to `systemctl restart apache2`.

## How to import into FreshRSS

Disable whatever automatic feed updates you are using (cron or alternative
methods). Otherwise you will probably end up with duplicate feeds.

Maybe you like to first try with a test user. FreshRSS makes that easy.
Login as admin user and create a new user. Now login as the new user.

Click: Subscription Management > Import / export

Under Import: choose file. Make sure that "Don't update feeds after import"
is selected or you will probably end up with duplicate feeds. Then click
Import. This might take a while, depending on your batch size. Repeat for
all other .json files.

That's it, you're done. All your feeds and articles have been imported.

When you are satisfied: login as admin user, delete the test user. Login
as your normal user and repeat the import.

Re-enable your automatic feed updates.

## FAQ

### What is it about these duplicate feeds you are warning me twice?

The importer detects existing feeds by means of comparing feed URLs. Now
take http://xkcd.com/rss.xml. If you have that in TT-RSS, it stays at it
is (see PR [#5629](https://github.com/FreshRSS/FreshRSS/pull/5629) for a
thrust at this).

Actually, xkcd.com answers with "301 Moved Permanently" and sends you to
https://xkcd.com/rss.xml. If you run FreshRSS feed updates, FreshRSS
notices the 301 and updates the feed configuration to use
https://xkcd.com/rss.xml in future.

When you run an import and allow FreshRSS to update the feeds subsequently,
this is exactly what happens. It also happens when feed updates are run by
cron. You can see that in FreshRSS under Subscription management:
click the gear wheel to the left of the feed and check the Feed URL field.

Now, when you import a second file, the importer compares
http://xkcd.com/rss.xml with https://xkcd.com/rss.xml, considers this a
mismatch and creates a new feed. That is how you end up with duplicate feeds.

To add insult to injury: delete one of the duplicate feeds and you will
lose articles. There is nothing you can do in the UI. You would need to
manipulate feed ids in database tables.

### FreshRSS shows more Favourites than TT-RSS shows Starred articles

FreshRSS counts starred articles and shows you two counts: all starred
articles and unread starred articles. The latter is consistent with what
TT-RSS displays.

