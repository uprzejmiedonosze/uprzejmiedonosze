<?php

namespace UprzejmieDonosze\Tests\Store;

use UprzejmieDonosze\Tests\DatabaseTestCase;

class ChatStoreTest extends DatabaseTestCase
{
    private const EMAIL = 'chat-store@example.com';

    public function testHistoryIsChronologicalAndLimitedToNewest(): void
    {
        foreach (['a', 'b', 'c'] as $t) \chat\add(self::EMAIL, 'user', $t);
        $this->assertSame(['b', 'c'], array_column(\chat\history(self::EMAIL, 2), 'content'));
    }

    public function testHistoryPagingWithBeforeId(): void
    {
        $ids = [];
        foreach (['a', 'b', 'c'] as $t) $ids[] = \chat\add(self::EMAIL, 'user', $t);
        $this->assertSame(['a', 'b'], array_column(\chat\history(self::EMAIL, 10, $ids[2]), 'content'));
    }

    public function testConversationsAreSeparatePerUser(): void
    {
        \chat\add(self::EMAIL, 'user', 'moje');
        \chat\add('other@example.com', 'user', 'cudze');
        $this->assertSame(['moje'], array_column(\chat\history(self::EMAIL), 'content'));
    }

    public function testMetaRoundTripsAsArray(): void
    {
        \chat\add(self::EMAIL, 'assistant', 'x', ['contact' => true, 'tools' => ['search_legal']]);
        $this->assertSame(['contact' => true, 'tools' => ['search_legal']], \chat\history(self::EMAIL)[0]['meta']);
    }

    public function testNotificationsAreUnreadUntilMarkedRead(): void
    {
        \chat\add(self::EMAIL, 'user', 'pytanie');
        \chat\notify(self::EMAIL, 'Zgłoszenie wysłane', '/app/list');
        $this->assertSame(1, \chat\unread(self::EMAIL));
        $this->assertSame('/app/list', \chat\history(self::EMAIL)[1]['meta']['link']);
        \chat\markRead(self::EMAIL);
        $this->assertSame(0, \chat\unread(self::EMAIL));
    }

    public function testRejectsUnknownRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \chat\add(self::EMAIL, 'system', 'x');
    }

    public function testUserMessagesSinceCountsOnlyUserRole(): void
    {
        \chat\add(self::EMAIL, 'user', 'a');
        \chat\add(self::EMAIL, 'assistant', 'b');
        $this->assertSame(1, \chat\userMessagesSince(self::EMAIL, time() - 60));
        $this->assertSame(0, \chat\userMessagesSince(self::EMAIL, time() + 60));
    }

    public function testArchiveStartsNewConversationButKeepsRows(): void
    {
        \chat\add(self::EMAIL, 'user', 'stare pytanie');
        \chat\notify(self::EMAIL, 'nieprzeczytane powiadomienie');
        \chat\add('other@example.com', 'user', 'cudze');
        $this->assertSame(1, \chat\unread(self::EMAIL));

        $this->assertSame(2, \chat\archive(self::EMAIL));
        $this->assertSame([], \chat\history(self::EMAIL));
        $this->assertSame(0, \chat\unread(self::EMAIL));
        $this->assertSame(1, \chat\userMessagesSince(self::EMAIL, time() - 60)); // limit pytań nie zeruje się
        $this->assertCount(1, \chat\history('other@example.com'));

        \chat\add(self::EMAIL, 'user', 'nowe pytanie');
        $this->assertSame(['nowe pytanie'], array_column(\chat\history(self::EMAIL), 'content'));
        $this->assertSame(0, \chat\archive('nobody@example.com'));

        $this->assertSame(3, \chat\removeAllForEmail(self::EMAIL)); // usunięcie konta kasuje też archiwum
    }

    public function testRemoveAllForEmail(): void
    {
        \chat\add(self::EMAIL, 'user', 'a');
        \chat\add('other@example.com', 'user', 'b');
        $this->assertSame(1, \chat\removeAllForEmail(self::EMAIL));
        $this->assertSame([], \chat\history(self::EMAIL));
        $this->assertCount(1, \chat\history('other@example.com'));
    }
}
