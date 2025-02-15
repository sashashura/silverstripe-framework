<?php

namespace SilverStripe\Control\Tests;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTP;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Control\Session;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;

/**
 * Tests the {@link HTTP} class
 *
 * @skipUpgrade
 */
class HTTPTest extends FunctionalTest
{
    protected function setUp(): void
    {
        parent::setUp();
        // Set to disabled at null forcing level
        HTTPCacheControlMiddleware::config()
            ->set('defaultState', 'disabled')
            ->set('defaultForcingLevel', 0);
        HTTPCacheControlMiddleware::reset();
    }

    public function testAddCacheHeaders()
    {
        $body = "<html><head></head><body><h1>Mysite</h1></body></html>";
        $response = new HTTPResponse($body, 200);
        HTTPCacheControlMiddleware::singleton()->publicCache();
        HTTPCacheControlMiddleware::singleton()->setMaxAge(30);

        $this->addCacheHeaders($response);
        $this->assertNotEmpty($response->getHeader('Cache-Control'));

        // Ensure cache headers are set correctly when disabled via config (e.g. when dev)
        HTTPCacheControlMiddleware::config()
            ->set('defaultState', 'disabled')
            ->set('defaultForcingLevel', HTTPCacheControlMiddleware::LEVEL_DISABLED);
        HTTPCacheControlMiddleware::reset();
        HTTPCacheControlMiddleware::singleton()->publicCache();
        HTTPCacheControlMiddleware::singleton()->setMaxAge(30);
        $response = new HTTPResponse($body, 200);
        $this->addCacheHeaders($response);
        $this->assertStringContainsString('no-cache', $response->getHeader('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->getHeader('Cache-Control'));
        $this->assertStringContainsString('must-revalidate', $response->getHeader('Cache-Control'));

        // Ensure max-age setting is respected in production.
        HTTPCacheControlMiddleware::config()
            ->set('defaultState', 'disabled')
            ->set('defaultForcingLevel', 0);
        HTTPCacheControlMiddleware::reset();
        HTTPCacheControlMiddleware::singleton()->publicCache();
        HTTPCacheControlMiddleware::singleton()->setMaxAge(30);
        $response = new HTTPResponse($body, 200);
        $this->addCacheHeaders($response);
        $this->assertStringContainsString('max-age=30', $response->getHeader('Cache-Control'));
        $this->assertStringNotContainsString('max-age=0', $response->getHeader('Cache-Control'));

        // Still "live": Ensure header's aren't overridden if already set (using purposefully different values).
        $headers = [
            'Vary' => '*',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'max-age=0, no-cache, no-store',
        ];
        foreach ($headers as $header => $value) {
            $response->addHeader($header, $value);
        }
        HTTPCacheControlMiddleware::reset();
        HTTPCacheControlMiddleware::singleton()->publicCache();
        HTTPCacheControlMiddleware::singleton()->setMaxAge(30);
        $this->addCacheHeaders($response);
        foreach ($headers as $header => $value) {
            $this->assertEquals($value, $response->getHeader($header));
        }
    }

    public function testConfigVary()
    {
        $body = "<html><head></head><body><h1>Mysite</h1></body></html>";
        $response = new HTTPResponse($body, 200);
        HTTPCacheControlMiddleware::singleton()
            ->setMaxAge(30)
            ->setVary('X-Requested-With, X-Forwarded-Protocol');
        $this->addCacheHeaders($response);

        // Vary set properly
        $v = $response->getHeader('Vary');
        $this->assertStringContainsString("X-Forwarded-Protocol", $v);
        $this->assertStringContainsString("X-Requested-With", $v);
        $this->assertStringNotContainsString("Cookie", $v);
        $this->assertStringNotContainsString("User-Agent", $v);
        $this->assertStringNotContainsString("Accept", $v);

        // No vary
        HTTPCacheControlMiddleware::singleton()
            ->setMaxAge(30)
            ->setVary(null);
        HTTPCacheControlMiddleware::reset();
        HTTPCacheControlMiddleware::config()
            ->set('defaultVary', []);

        $response = new HTTPResponse($body, 200);
        $this->addCacheHeaders($response);
        $v = $response->getHeader('Vary');
        $this->assertEmpty($v);
    }

    public function testDeprecatedVaryHandling()
    {
        /** @var Config */
        Config::modify()->set(
            HTTP::class,
            'vary',
            'X-Foo'
        );
        $response = new HTTPResponse('', 200);
        $this->addCacheHeaders($response);
        $header = $response->getHeader('Vary');
        $this->assertStringContainsString('X-Foo', $header);
    }

    public function testDeprecatedCacheControlHandling()
    {
        HTTPCacheControlMiddleware::singleton()->publicCache();

        /** @var Config */
        Config::modify()->set(
            HTTP::class,
            'cache_control',
            [
                'no-store' => true,
                'no-cache' => true,
            ]
        );
        $response = new HTTPResponse('', 200);
        $this->addCacheHeaders($response);
        $header = $response->getHeader('Cache-Control');
        $this->assertStringContainsString('no-store', $header);
        $this->assertStringContainsString('no-cache', $header);
    }

    public function testDeprecatedCacheControlHandlingOnMaxAge()
    {
        HTTPCacheControlMiddleware::singleton()->publicCache();

        /** @var Config */
        Config::modify()->set(
            HTTP::class,
            'cache_control',
            [
                // Needs to be separate from no-cache and no-store,
                // since that would unset max-age
                'max-age' => 99,
            ]
        );
        $response = new HTTPResponse('', 200);
        $this->addCacheHeaders($response);
        $header = $response->getHeader('Cache-Control');
        $this->assertStringContainsString('max-age=99', $header);
    }

    public function testDeprecatedCacheControlHandlingThrowsWithUnknownDirectives()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Found unsupported legacy directives in HTTP\.cache_control: unknown/');
        /** @var Config */
        Config::modify()->set(
            HTTP::class,
            'cache_control',
            [
                'no-store' => true,
                'unknown' => true,
            ]
        );
        $response = new HTTPResponse('', 200);
        $this->addCacheHeaders($response);
    }

    /**
     * Tests {@link HTTP::getLinksIn()}
     */
    public function testGetLinksIn()
    {
        $content = '
			<h2><a href="/">My Cool Site</a></h2>

			<p>
				A boy went <a href="home/">home</a> to see his <span><a href="mother/">mother</a></span>. This
				involved a short <a href="$Journey">journey</a>, as well as some <a href="space travel">space travel</a>
				and <a href=unquoted>unquoted</a> events, as well as a <a href=\'single quote\'>single quote</a> from
				his <a href="/father">father</a>.
			</p>

			<p>
				There were also some elements with extra <a class=attribute href=\'attributes\'>attributes</a> which
				played a part in his <a href=journey"extra id="JourneyLink">journey</a>. HE ALSO DISCOVERED THE
				<A HREF="CAPS LOCK">KEY</a>. Later he got his <a href="quotes \'mixed\' up">mixed up</a>.
			</p>
		';

        $expected =  [
            '/', 'home/', 'mother/', '$Journey', 'space travel', 'unquoted', 'single quote', '/father', 'attributes',
            'journey', 'CAPS LOCK', 'quotes \'mixed\' up'
        ];

        $result = HTTP::getLinksIn($content);

        // Results don't necessarily come out in the order they are in the $content param.
        sort($result);
        sort($expected);

        $this->assertIsArray($result);
        $this->assertEquals($expected, $result, 'Test that all links within the content are found.');
    }

    /**
     * Tests {@link HTTP::setGetVar()}
     */
    public function testSetGetVar()
    {
        // Hackery to work around volatile URL formats in test invocation,
        // and the inability of Director::absoluteBaseURL() to produce consistent URLs.
        Director::mockRequest(function (HTTPRequest $request) {
            $controller = new Controller();
            $controller->setRequest($request);
            $controller->pushCurrent();
            try {
                $this->assertStringContainsString(
                    'relative/url?foo=bar',
                    HTTP::setGetVar('foo', 'bar'),
                    'Omitting a URL falls back to current URL'
                );
            } finally {
                $controller->popCurrent();
            }
        }, 'relative/url/');

        $this->assertEquals(
            '/relative/url?foo=bar',
            HTTP::setGetVar('foo', 'bar', 'relative/url'),
            'Relative URL without existing query params'
        );

        $this->assertEquals(
            '/relative/url?baz=buz&foo=bar',
            HTTP::setGetVar('foo', 'bar', '/relative/url?baz=buz'),
            'Relative URL with existing query params, and new added key'
        );

        $this->assertEquals(
            'http://test.com/?foo=new&buz=baz',
            HTTP::setGetVar('foo', 'new', 'http://test.com/?foo=old&buz=baz'),
            'Absolute URL without path and multiple existing query params, overwriting an existing parameter'
        );

        $this->assertStringContainsString(
            'http://test.com/?foo=new',
            HTTP::setGetVar('foo', 'new', 'http://test.com/?foo=&foo=old'),
            'Absolute URL and empty query param'
        );
        // http_build_query() escapes angular brackets, they should be correctly urldecoded by the browser client
        $this->assertEquals(
            'http://test.com/?foo%5Btest%5D=one&foo%5Btest%5D=two',
            HTTP::setGetVar('foo[test]', 'two', 'http://test.com/?foo[test]=one'),
            'Absolute URL and PHP array query string notation'
        );

        $urls = [
            'http://www.test.com:8080',
            'http://test.com:3000/',
            'http://test.com:3030/baz/',
            'http://baz:foo@test.com',
            'http://baz@test.com/',
            'http://baz:foo@test.com:8080',
            'http://baz@test.com:8080'
        ];

        foreach ($urls as $testURL) {
            $this->assertEquals(
                $testURL . '?foo=bar',
                HTTP::setGetVar('foo', 'bar', $testURL),
                'Absolute URL and Port Number'
            );
        }
    }

    /**
     * Test that the the get_mime_type() works correctly
     */
    public function testGetMimeType()
    {
        $this->assertEquals('text/plain', HTTP::get_mime_type('file.csv'));
        $this->assertEquals('image/gif', HTTP::get_mime_type('file.gif'));
        $this->assertEquals('text/html', HTTP::get_mime_type('file.html'));
        $this->assertEquals('image/jpeg', HTTP::get_mime_type('file.jpg'));
        $this->assertEquals('image/jpeg', HTTP::get_mime_type('upperfile.JPG'));
        $this->assertEquals('image/png', HTTP::get_mime_type('file.png'));
        $this->assertEquals(
            'image/vnd.adobe.photoshop',
            HTTP::get_mime_type('file.psd')
        );
        $this->assertEquals('audio/x-wav', HTTP::get_mime_type('file.wav'));
    }

    /**
     * Test that absoluteURLs correctly transforms urls within CSS to absolute
     */
    public function testAbsoluteURLsCSS()
    {
        $this->withBaseURL(
            'http://www.silverstripe.org/',
            function () {

                // background-image
                // Note that using /./ in urls is absolutely acceptable
                $this->assertEquals(
                    '<div style="background-image: url(\'http://www.silverstripe.org/./images/mybackground.gif\');">' . 'Content</div>',
                    HTTP::absoluteURLs('<div style="background-image: url(\'./images/mybackground.gif\');">Content</div>')
                );

                // background
                $this->assertEquals(
                    '<div style="background: url(\'http://www.silverstripe.org/images/mybackground.gif\');">Content</div>',
                    HTTP::absoluteURLs('<div style="background: url(\'images/mybackground.gif\');">Content</div>')
                );

                // list-style-image
                $this->assertEquals(
                    '<div style=\'background: url(http://www.silverstripe.org/list.png);\'>Content</div>',
                    HTTP::absoluteURLs('<div style=\'background: url(list.png);\'>Content</div>')
                );

                // list-style
                $this->assertEquals(
                    '<div style=\'background: url("http://www.silverstripe.org/./assets/list.png");\'>Content</div>',
                    HTTP::absoluteURLs('<div style=\'background: url("./assets/list.png");\'>Content</div>')
                );
            }
        );
    }

    /**
     * Test that absoluteURLs correctly transforms urls within html attributes to absolute
     */
    public function testAbsoluteURLsAttributes()
    {
        $this->withBaseURL(
            'http://www.silverstripe.org/',
            function () {
                //empty links
                $this->assertEquals(
                    '<a href="http://www.silverstripe.org/">test</a>',
                    HTTP::absoluteURLs('<a href="">test</a>')
                );

                $this->assertEquals(
                    '<a href="http://www.silverstripe.org/">test</a>',
                    HTTP::absoluteURLs('<a href="/">test</a>')
                );

                //relative
                $this->assertEquals(
                    '<a href="http://www.silverstripe.org/">test</a>',
                    HTTP::absoluteURLs('<a href="./">test</a>')
                );
                $this->assertEquals(
                    '<a href="http://www.silverstripe.org/">test</a>',
                    HTTP::absoluteURLs('<a href=".">test</a>')
                );

                // links
                $this->assertEquals(
                    '<a href=\'http://www.silverstripe.org/blog/\'>SS Blog</a>',
                    HTTP::absoluteURLs('<a href=\'/blog/\'>SS Blog</a>')
                );

                // background
                // Note that using /./ in urls is absolutely acceptable
                $this->assertEquals(
                    '<div background="http://www.silverstripe.org/./themes/silverstripe/images/nav-bg-repeat-2.png">' . 'SS Blog</div>',
                    HTTP::absoluteURLs('<div background="./themes/silverstripe/images/nav-bg-repeat-2.png">SS Blog</div>')
                );

                //check dot segments
                // Assumption: dots are not removed
                //if they were, the url should be: http://www.silverstripe.org/abc
                $this->assertEquals(
                    '<a href="http://www.silverstripe.org/test/page/../../abc">Test</a>',
                    HTTP::absoluteURLs('<a href="test/page/../../abc">Test</a>')
                );

                // image
                $this->assertEquals(
                    '<img src=\'http://www.silverstripe.org/themes/silverstripe/images/logo-org.png\' />',
                    HTTP::absoluteURLs('<img src=\'themes/silverstripe/images/logo-org.png\' />')
                );

                // link
                $this->assertEquals(
                    '<link href=http://www.silverstripe.org/base.css />',
                    HTTP::absoluteURLs('<link href=base.css />')
                );

                // Test special characters are retained
                $this->assertEquals(
                    '<a href="http://www.silverstripe.org/Security/changepassword?m=3&amp;t=7214fdfde">password reset link</a>',
                    HTTP::absoluteURLs('<a href="/Security/changepassword?m=3&amp;t=7214fdfde">password reset link</a>')
                );
            }
        );
    }

    /**
     *  Make sure URI schemes are not rewritten
     */
    public function testURISchemes()
    {
        $this->withBaseURL(
            'http://www.silverstripe.org/',
            function ($test) {

                // mailto
                $this->assertEquals(
                    '<a href=\'mailto:admin@silverstripe.org\'>Email Us</a>',
                    HTTP::absoluteURLs('<a href=\'mailto:admin@silverstripe.org\'>Email Us</a>'),
                    'Email links are not rewritten'
                );

                // data uri
                $this->assertEquals(
                    '<img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAUAAAAFCAYAAACNbyblAAAAHElEQVQI12P4//8/w38' . 'GIAXDIBKE0DHxgljNBAAO9TXL0Y4OHwAAAABJRU5ErkJggg==" alt="Red dot" />',
                    HTTP::absoluteURLs(
                        '<img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAUAAAAFCAYAAACNbyblAAAAH' . 'ElEQVQI12P4//8/w38GIAXDIBKE0DHxgljNBAAO9TXL0Y4OHwAAAABJRU5ErkJggg==" alt="Red dot" />'
                    ),
                    'Data URI links are not rewritten'
                );

                // call
                $this->assertEquals(
                    '<a href="callto:12345678" />',
                    HTTP::absoluteURLs('<a href="callto:12345678" />'),
                    'Call to links are not rewritten'
                );
            }
        );
    }

    public function testFilename2url()
    {
        $this->withBaseURL(
            'http://www.silverstripe.org/',
            function () {
                $frameworkTests = ltrim(FRAMEWORK_DIR . '/tests', '/');
                $this->assertEquals(
                    "http://www.silverstripe.org/$frameworkTests/php/Control/HTTPTest.php",
                    HTTP::filename2url(__FILE__)
                );
            }
        );
    }

    /**
     * Process cache headers on a response
     *
     * @param HTTPResponse $response
     */
    protected function addCacheHeaders(HTTPResponse $response)
    {
        // Mock request
        $session = new Session([]);
        $request = new HTTPRequest('GET', '/');
        $request->setSession($session);

        // Run middleware
        HTTPCacheControlMiddleware::singleton()
            ->process($request, function () use ($response) {
                return $response;
            });
    }
}
