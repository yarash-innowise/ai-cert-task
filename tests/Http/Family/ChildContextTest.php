<?php

declare(strict_types=1);

namespace Tests\Http\Family;

use PHPUnit\Framework\Attributes\Group;

final class ChildContextTest extends FamilyHttpTestCase
{
    private string $orgA;
    private string $orgB;
    private string $orgC;
    private string $parent;
    private string $mia;
    private string $sam;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->orgA] = $this->trainerWithOrganization('Ace Tennis Academy');
        [, $this->orgB] = $this->trainerWithOrganization('Baseline Club');
        [, $this->orgC] = $this->trainerWithOrganization('Court Three');
        $this->parent = $this->signInAs('player');
        $me = $this->selfOf($this->parent);
        $this->mia = $this->childOf($this->parent, 'Mia', 'Parent');
        $this->sam = $this->childOf($this->parent, 'Sam', 'Parent', '2019-03-03', 'male');
        $this->associate($me, $this->orgC);
        $this->associate($this->mia, $this->orgA);
        $this->associate($this->mia, $this->orgB);
        $this->associate($this->sam, $this->orgC);
        $this->signInAsChildLogin($this->parent, $this->mia);
    }

    #[Group('AC-37')]
    public function testAChildLoginSeesAContextSelectorOfOnlyItsOwnTrainers(): void
    {
        $body = $this->body($this->get('/family/context'));

        self::assertStringContainsString('<h2 id="group-1">Your Trainers</h2>', $body);
        self::assertStringContainsString('Ace Tennis Academy', $body);
        self::assertStringContainsString('Baseline Club', $body);
        self::assertStringNotContainsString('Court Three', $body, "a sibling's or the parent's Trainer is not the child's");
        self::assertStringNotContainsString('Your Training', $body);
        self::assertStringNotContainsString('Sam', $body);
        self::assertStringNotContainsString('>Me<', $body);
    }

    #[Group('AC-37')]
    public function testTheHeaderSelectorOfAChildOffersOnlyItsOwnTrainers(): void
    {
        $body = $this->body($this->get('/family/events'));

        self::assertStringContainsString('<optgroup label="Your Trainers">', $body);
        self::assertSame(2, substr_count($body, '<option value='));
    }

    #[Group('AC-37'), Group('AC-46')]
    public function testAChildCanSwitchBetweenItsOwnTrainersButNotToAnyoneElses(): void
    {
        $switch = $this->post('/family/context', ['context' => $this->orgB . ':' . $this->mia]);
        $siblingContext = $this->post('/family/context', ['context' => $this->orgC . ':' . $this->sam]);
        $parentContext = $this->post('/family/context', ['context' => $this->orgC . ':' . (string) $this->scalar("SELECT id FROM player_profiles WHERE kind = 'self'")]);

        self::assertSame(302, $switch->getStatusCode());
        self::assertSame(404, $siblingContext->getStatusCode());
        self::assertSame(404, $parentContext->getStatusCode());
        self::assertSame($this->orgB . ':' . $this->mia, $this->storedContext());
        self::assertStringContainsString('Showing: Baseline Club', $this->body($this->get('/family/content')));
    }
}
