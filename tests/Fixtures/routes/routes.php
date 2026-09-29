
// swerve-codeigniter's test routes (tests/Fixtures/routes/routes.php)
$routes->get('json', 'SwerveTest::json');
$routes->post('json', 'SwerveTest::jsonEcho');
$routes->get('form', 'SwerveTest::form', ['filter' => 'csrf']);
$routes->post('form', 'SwerveTest::submit', ['filter' => 'csrf']);
$routes->post('upload', 'SwerveTest::upload');
$routes->get('download', 'SwerveTest::download');
$routes->get('isolation/(:segment)', 'SwerveTest::isolation/$1', ['as' => 'isolation']);
$routes->get('overlap/(:segment)', 'SwerveTest::overlap/$1');
$routes->get('overlap-db/(:segment)', 'SwerveTest::overlapDb/$1');
$routes->get('counter', 'SwerveTest::counter');
$routes->post('flash', 'SwerveTest::setFlash');
$routes->get('flash', 'SwerveTest::flash');
$routes->get('stream', 'SwerveTest::stream');
$routes->get('ws', 'SwerveTest::ws');
$routes->get('news', 'SwerveTest::news');
$routes->post('news', 'SwerveTest::publish');
$routes->get('news/open', 'SwerveTest::open');
$routes->post('login', 'SwerveTest::login');
$routes->get('me', 'SwerveTest::me');
$routes->get('me/late', 'SwerveTest::meLate');
$routes->get('slow', 'SwerveTest::slow');
$routes->get('usleep', 'SwerveTest::usleep');
$routes->get('boom', 'SwerveTest::boom');
$routes->get('memory', 'SwerveTest::memory');
