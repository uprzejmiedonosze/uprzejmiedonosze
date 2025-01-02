<?php

namespace UprzejmieDonosze\Tests\Dataclasses;

use Exception;
use MissingParamException;
use PHPUnit\Framework\TestCase;
use user\User;

class UserTest extends TestCase
{
    public function testIsRegistered()
    {
        $user = new User();
        $this->assertFalse($user->isRegistered());

        $user->data->name = 'John Doe';
        $user->data->address = '123 Main St';
        $this->assertTrue($user->isRegistered());
    }

    public function testSetLastLocation()
    {
        $user = new User();
        $user->setLastLocation('52.069321,19.480311');
        $this->assertEquals('52.069321,19.480311', $user->getLastLocation());
    }

    public function testGetLastLocation()
    {
        $user = new User();
        $this->assertEquals('52.069321,19.480311', $user->getLastLocation());
    }

    public function testIsAdmin()
    {
        $user = new User();
        $user->data->email = 'admin@example.com';
        $this->assertFalse($user->isAdmin());

        $user->data->email = 'szymon@nieradka.net';
        $this->assertTrue($user->isAdmin());
    }

    public function testIsModerator()
    {
        $user = new User();
        $user->data->email = 'moderator@example.com';
        $this->assertFalse($user->isModerator());
    }

    public function testUpdateUserDataInvalid()
    {
        $user = new User();
        $this->expectException(MissingParamException::class);
        $this->expectExceptionMessage('Podaj adres z ulicą, numerem mieszkania i miejscowością');
        $user->updateUserData('John Doe', '1234567890', '123 Main St', 'email@example.com', true, true);
    }

    public function testUpdateUserData()
    {
        $user = new User();
        $result = $user->updateUserData('John Doe', '1234567890', 'Ulica 13, Miasto', 'email@example.com', true, true);
        $this->assertTrue($result);
        $this->assertEquals('John Doe', $user->data->name);
        $this->assertEquals('Ulica 13, Miasto', $user->data->address);
    }


    public function testConfirmTerms()
    {
        $user = new User();
        $user->confirmTerms();
        $this->assertNotNull($user->data->termsConfirmation);
    }

    public function testCheckTermsConfirmation()
    {
        $user = new User();
        $this->assertFalse($user->checkTermsConfirmation());

        $user->confirmTerms();
        $this->assertTrue($user->checkTermsConfirmation());
    }

    public function testHasApps()
    {
        $user = new User();
        $this->assertFalse($user->hasApps());

        $user->appsCount = 1;
        $this->assertTrue($user->hasApps());
    }

    public function testGuessSex()
    {
        $user = new User();
        $user->data->name = 'John Doe';
        $this->assertEquals('świadomy', $user->guessSex()["swiadoma"]);

        $user->data->name = 'Joanna Doe';
        $this->assertEquals('świadoma', $user->guessSex()["swiadoma"]);
    }

    public function testGetSanitizedName()
    {
        $user = new User();
        $user->data->name = 'John Doe';
        $this->assertEquals('John-Doe', $user->getSanitizedName());
    }

    public function testGetFirstName()
    {
        $user = new User();
        $user->data->name = 'John Doe';
        $this->assertEquals('John', $user->getFirstName());
    }

    public function testGetEmail()
    {
        $user = new User();
        $user->data->email = 'email@example.com';
        $this->assertEquals('email@example.com', $user->getEmail());
    }

    public function testCanExposeData()
    {
        $user = new User();
        $this->assertFalse($user->canExposeData());
    }

    public function testStopAgresji()
    {
        $user = new User();
        $this->assertFalse($user->stopAgresji());

        $user->data->stopAgresji = true;
        $this->assertTrue($user->stopAgresji());
    }

    public function testAutoSend()
    {
        $user = new User();
        $this->assertTrue($user->autoSend());
    }

    public function testShareRecydywa()
    {
        $user = new User();
        $this->assertTrue($user->shareRecydywa());
    }

    public function testEncode()
    {
        $user = new User();
        $user->number = 3;
        $user->data->name = 'John Doe';
        $user->data->email = 'John@Doe';
        $_SESSION['user_id'] = 1;

        $encoded = $user->encode();
        $decoded = new User($encoded);
        $encoded = json_decode($encoded);

        $this->assertNotEquals($user->data->name, $encoded->data->name);
        $this->assertEquals($user->data->name, $decoded->data->name);
    }

    public function testEncodeNoSession()
    {
        $user = new User();
        $user->number = 3;
        $user->data->name = 'John Doe';
        $user->data->email = 'John@Doe';
        $_SESSION['user_id'] = 1;

        $encoded = $user->encode();

        $_SESSION['user_id'] = null;
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("User data is encrypted, but no user_id is set");
        new User($encoded);
    }
}
