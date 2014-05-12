There are a few environment specific details that need to be setup in order for the acceptance tests to work.

1) The configuration values in the various Codeception config files must match the environment (e.g., database
   credentials, web server URL, etc).

2) wp-config.php should define DB_NAME as 'wp_dev_tests' if $_SERVER['HTTP_USER_AGENT'] contains 'BrowserKit'
   or 'Guzzle', so that the test database will be used instead of the live one.

3) There should be an mu-plugin with a `gapup_nonce_expiration` filter callback that returns (int) 5, so that
   we don't have to wait the full 3 minutes during nonce expiration tests.
