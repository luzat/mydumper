# mydumper

This is a basic PHP script which invokes `mysqldump` to dump a MySQL database.
Error handling is rudimentary. The script assumes that the configured databases
are indeed MySQL databases and `mysqldump` is available.

**Please note:** This is mostly used internally, but I am happy if anyone
finds this useful or provides improvements.

## Features

* Detects most database configurations for the following applications:
  * Joomla! (`configuration.php`)
  * JTL Shop (`includes/config.JTL-Shop.ini.php`)
  * Magento 1.x (`app/etc/local.xml`)
  * TYPO3 CMS (`typo3conf/LocalConfiguration.php`, but not `AdditionalConfiguration.php`)
  * WordPress (`wp-config.php`)
* Connection options can be overriden by GET or POST parameters
* Returned files are gzipped and named after the database
* Secured by basic HTTP auth (configure at head of script!)

## Getting started

1. Adjust user and password at top of `mydumper.php`.
2. Upload to application root directory.
3. Open `https://example.com/mydumper.php` in browser, with `curl` or `wget` (provide user name/password!).
4. Store dump.

Optionally append some or all GET parameters (POST works, too) to provide or override configuration, e.g.

```
https://example.com/mydumper.php?debug=1&user=John&password=J0hn&db=db123&host=localhost&port=3307&mysqldumper=/usr/sbin/mysqldumper
```

Debug information — if enabled — is stored in `mydumper.err.txt` and contains confidential database connection information and `mysqldump`'s error output.

Automatic detection of applications can be turned off by passing `no_detect=1`.

## Author

**Thomas Luzat** - [luzat.com](https://luzat.com/)

## License

This project is licensed under the [ISC License](LICENSE.md).
