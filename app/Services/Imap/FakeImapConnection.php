<?php

namespace App\Services\Imap;

use PHPUnit\Framework\AssertionFailedError;

/**
 * Fake IMAP connection for testing.
 *
 * This class records all operations and can be configured to return
 * specific results for testing different scenarios.
 */
class FakeImapConnection extends ImapConnection
{
    /** @var array<int, array{folder: string, message: string, flags: ?string, date: ?string}> */
    public array $appendedMessages = [];

    public bool $openCalled = false;

    public bool $closeCalled = false;

    public ?string $lastUsername = null;

    public ?string $lastPassword = null;

    public ?string $lastFolder = null;

    public bool $shouldFailOpen = false;

    public bool $shouldFailAppend = false;

    /** @var array<string, array{name: string, attributes: string[]}> */
    public array $folders = [
        'INBOX' => ['name' => 'INBOX', 'attributes' => []],
        'Sent' => ['name' => 'Sent', 'attributes' => ['\\Sent']],
        'Drafts' => ['name' => 'Drafts', 'attributes' => ['\\Drafts']],
        'Trash' => ['name' => 'Trash', 'attributes' => ['\\Trash']],
    ];

    /** @var string[] */
    public array $createdFolders = [];

    /** @var string[] */
    public array $subscribedFolders = [];

    public bool $shouldFailCreateFolder = false;

    public function __construct()
    {
        parent::__construct('fake.test', 993, 'ssl');
    }

    public function open(string $username, string $password, string $folder = 'INBOX'): void
    {
        if ($this->shouldFailOpen) {
            throw new \RuntimeException('Fake IMAP connection failed');
        }

        $this->openCalled = true;
        $this->lastUsername = $username;
        $this->lastPassword = $password;
        $this->lastFolder = $folder;
    }

    public function close(): void
    {
        $this->closeCalled = true;
    }

    public function isOpen(): bool
    {
        return $this->openCalled && ! $this->closeCalled;
    }

    public function append(string $folder, string $message, ?string $flags = null, ?string $internalDate = null): bool
    {
        if ($this->shouldFailAppend) {
            return false;
        }

        $this->appendedMessages[] = [
            'folder' => $folder,
            'message' => $message,
            'flags' => $flags,
            'date' => $internalDate,
        ];

        return true;
    }

    /**
     * Assert that a message was appended.
     */
    public function assertAppended(?string $folder = null): void
    {
        if (empty($this->appendedMessages)) {
            throw new AssertionFailedError('No messages were appended to the IMAP server.');
        }

        if ($folder !== null) {
            $folders = array_column($this->appendedMessages, 'folder');
            if (! in_array($folder, $folders, true)) {
                throw new AssertionFailedError("No message was appended to folder '{$folder}'.");
            }
        }
    }

    /**
     * Assert that no messages were appended.
     */
    public function assertNothingAppended(): void
    {
        if (! empty($this->appendedMessages)) {
            $count = count($this->appendedMessages);
            throw new AssertionFailedError("Expected no messages to be appended, but {$count} were.");
        }
    }

    /**
     * Assert that a message was appended containing specific content.
     */
    public function assertAppendedContaining(string $content): void
    {
        foreach ($this->appendedMessages as $appended) {
            if (str_contains($appended['message'], $content)) {
                return;
            }
        }

        throw new AssertionFailedError("No appended message contains '{$content}'.");
    }

    /**
     * Get the last appended message content.
     */
    public function lastAppendedMessage(): ?string
    {
        if (empty($this->appendedMessages)) {
            return null;
        }

        return end($this->appendedMessages)['message'];
    }

    /**
     * @return array<string, array{name: string, attributes: string[]}>
     */
    public function listFolders(string $pattern = '*'): array
    {
        return $this->folders;
    }

    public function findSentFolder(): ?string
    {
        $folderNames = array_keys($this->folders);

        foreach ($this->folders as $name => $info) {
            if (in_array('\\Sent', $info['attributes'], true)) {
                return $name;
            }
        }

        $candidates = ['Sent', 'Sent Items', 'Sent Mail'];
        foreach ($candidates as $candidate) {
            if (isset($this->folders[$candidate])) {
                return $candidate;
            }

            foreach ($folderNames as $name) {
                if (strcasecmp($name, $candidate) === 0) {
                    return $name;
                }
            }
        }

        foreach ($folderNames as $name) {
            if (stripos($name, 'sent') !== false && stripos($name, 'unsent') === false) {
                return $name;
            }
        }

        return null;
    }

    public function createFolder(string $folder): bool
    {
        if ($this->shouldFailCreateFolder) {
            return false;
        }

        $this->createdFolders[] = $folder;
        $this->folders[$folder] = ['name' => $folder, 'attributes' => []];

        return true;
    }

    public function subscribeFolder(string $folder): bool
    {
        $this->subscribedFolders[] = $folder;

        return true;
    }

    /**
     * Clear all folders except INBOX for testing folder creation scenarios.
     */
    public function clearFolders(): void
    {
        $this->folders = [
            'INBOX' => ['name' => 'INBOX', 'attributes' => []],
        ];
    }

    /**
     * Reset the fake state for a new test.
     */
    public function reset(): void
    {
        $this->appendedMessages = [];
        $this->openCalled = false;
        $this->closeCalled = false;
        $this->lastUsername = null;
        $this->lastPassword = null;
        $this->lastFolder = null;
        $this->shouldFailOpen = false;
        $this->shouldFailAppend = false;
        $this->folders = [
            'INBOX' => ['name' => 'INBOX', 'attributes' => []],
            'Sent' => ['name' => 'Sent', 'attributes' => ['\\Sent']],
            'Drafts' => ['name' => 'Drafts', 'attributes' => ['\\Drafts']],
            'Trash' => ['name' => 'Trash', 'attributes' => ['\\Trash']],
        ];
        $this->createdFolders = [];
        $this->subscribedFolders = [];
        $this->shouldFailCreateFolder = false;
    }
}
