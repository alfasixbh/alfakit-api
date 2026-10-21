<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\CheckboxList;
use AlfaSix\Alfakit\Forms\Components\Radio;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Components\Callout;
use AlfaSix\Alfakit\Schemas\Components\Grid;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitApi\ApiPlugin;
use AlfaSix\AlfakitApi\Contracts\AccessToken;
use AlfaSix\AlfakitApi\Contracts\QueryableTokenRepository;
use AlfaSix\AlfakitApi\Models\ApiToken;
use AlfaSix\AlfakitApi\ResourceApi;
use AlfaSix\AlfakitApi\Tokens\Abilities;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Lets users create personal access tokens (name, abilities per resource and operation,
 * expiry) and revoke them. A new token is shown once, right after it was created.
 *
 * On tenancy panels tokens belong to the current tenant and only work for it.
 */
class ApiTokens extends Page
{
    /** Session key holding the encrypted new token until the page shows it once. */
    public const NEW_TOKEN_SESSION_KEY = 'alfakit-api.new-token';

    protected static ?string $slug = 'api-tokens';

    protected static bool $isDiscovered = false;

    protected static ?string $navigationIcon = 'key-round';

    protected ?string $newToken = null;

    public static function getPlugin(): ApiPlugin
    {
        return ApiPlugin::get() ?? ApiPlugin::make();
    }

    public static function canAccess(): bool
    {
        return alfakit()->getCurrentOrDefaultPanel()->auth()->user() instanceof Model && static::getPlugin()->userCanManageTokens();
    }

    public function getTitle(): string
    {
        return (string) __('alfakit-api::api.tokens.title');
    }

    public function getSubheading(): ?string
    {
        return (string) __('alfakit-api::api.tokens.subheading');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('alfakit-api::api.tokens.navigation');
    }

    public static function getNavigationGroup(): ?string
    {
        return static::getPlugin()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return static::getPlugin()->getNavigationSort();
    }

    public function mount(mixed ...$parameters): void
    {
        // Shown once: the token leaves the session as soon as the page renders it.
        $encrypted = app()->bound('session') ? session()->pull(self::NEW_TOKEN_SESSION_KEY) : null;

        if (is_string($encrypted)) {
            try {
                $this->newToken = Crypt::decryptString($encrypted);
            } catch (DecryptException) {
                $this->newToken = null;
            }
        }
    }

    protected function flushCaches(): void
    {
        parent::flushCaches();

        $this->newToken = null;
    }

    protected function getHeaderActions(): array
    {
        return [$this->getCreateTokenAction()];
    }

    public function getCreateTokenAction(): Action
    {
        return Action::make('createToken')
            ->label(__('alfakit-api::api.tokens.create.label'))
            ->icon('plus')
            ->button()
            ->modalHeading(__('alfakit-api::api.tokens.create.heading'))
            ->modalDescription(__('alfakit-api::api.tokens.create.description'))
            ->modalSubmitActionLabel(__('alfakit-api::api.tokens.create.submit'))
            ->modalWidth('2xl')
            ->form(fn (): array => $this->getTokenFormComponents())
            ->authorize(static fn (): bool => static::canAccess())
            ->action(function (array $data): void {
                $this->createToken($data);
            });
    }

    /**
     * @return array<\AlfaSix\Alfakit\Schemas\Components\Component>
     */
    protected function getTokenFormComponents(): array
    {
        $plugin = static::getPlugin();
        $resources = $plugin->getResourceApis($this->getPanel());

        $components = [
            TextInput::make('name')
                ->label(__('alfakit-api::api.tokens.fields.name'))
                ->placeholder(__('alfakit-api::api.tokens.fields.name_placeholder'))
                ->required()
                ->maxLength(255)
                ->autofocus(),
            Select::make('expires_in')
                ->label(__('alfakit-api::api.tokens.fields.expires'))
                ->options($this->getExpirationOptions())
                ->default($this->expirationKey($plugin->getDefaultTokenExpiration()))
                ->selectablePlaceholder(false)
                ->required(),
            Radio::make('access')
                ->label(__('alfakit-api::api.tokens.fields.access'))
                // Without exposed resources there are no abilities to pick: full access only.
                ->options([
                    'all' => __('alfakit-api::api.tokens.fields.access_all'),
                    ...($resources === [] ? [] : ['custom' => __('alfakit-api::api.tokens.fields.access_custom')]),
                ])
                ->helperText(__('alfakit-api::api.tokens.fields.access_help'))
                ->default($resources === [] ? 'all' : 'custom')
                ->required()
                ->live(),
        ];

        $abilityFields = [];

        foreach ($resources as $slug => $api) {
            $abilityFields[] = CheckboxList::make('abilities.'.$slug)
                ->label($api->getLabel())
                ->options($this->getAbilityOptions($slug, $api))
                ->bulkToggleable()
                ->columns(2);
        }

        if ($abilityFields !== []) {
            // Full width: in the modal's two-column form a half-width cell squeezes the lists
            // and their "Select all / Deselect all" links out of the modal.
            $components[] = Grid::make(['default' => 1, 'md' => 2])
                ->schema($abilityFields)
                ->columnSpanFull()
                ->visible(static fn (Get $get): bool => $get('access') === 'custom');
        }

        return $components;
    }

    /**
     * @return array<string, string>
     */
    protected function getAbilityOptions(string $slug, ResourceApi $api): array
    {
        $options = [];
        $operations = [
            Abilities::READ => in_array('index', $api->getOperations(), true) || in_array('show', $api->getOperations(), true),
            Abilities::CREATE => $api->hasOperation('store'),
            Abilities::UPDATE => $api->hasOperation('update'),
            Abilities::DELETE => $api->hasOperation('destroy'),
        ];

        foreach ($operations as $operation => $isExposed) {
            if ($isExposed) {
                $options[Abilities::for($slug, $operation)] = (string) __('alfakit-api::api.abilities.'.$operation);
            }
        }

        foreach ($api->getBulkActions() as $action) {
            $options[Abilities::bulk($slug, $action)] = (string) __('alfakit-api::api.abilities.bulk', ['action' => $action]);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    protected function getExpirationOptions(): array
    {
        $options = [];

        foreach (static::getPlugin()->getTokenExpirationOptions() as $days) {
            $options[$this->expirationKey($days)] = $days === null
                ? (string) __('alfakit-api::api.tokens.fields.never')
                : trans_choice('alfakit-api::api.tokens.fields.days', $days, ['count' => $days]);
        }

        return $options;
    }

    protected function expirationKey(?int $days): string
    {
        return $days === null ? 'never' : (string) $days;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createToken(array $data): void
    {
        $panel = $this->getPanel();
        $user = $panel->auth()->user();

        abort_unless($user instanceof Model && static::canAccess(), 403);

        $plugin = static::getPlugin();
        $abilities = [];

        if (($data['access'] ?? null) === 'all') {
            $abilities = [Abilities::ALL];
        } else {
            // Only abilities the form offered: a crafted request can't grant anything else.
            $offered = [];

            foreach ($plugin->getResourceApis($panel) as $slug => $api) {
                array_push($offered, ...array_keys($this->getAbilityOptions($slug, $api)));
            }

            $selected = is_array($data['abilities'] ?? null) ? $data['abilities'] : [];

            foreach ($selected as $values) {
                foreach (is_array($values) ? $values : [] as $ability) {
                    if (is_string($ability) && in_array($ability, $offered, true)) {
                        $abilities[] = $ability;
                    }
                }
            }
        }

        if ($abilities === []) {
            Notification::make()->danger()->title(__('alfakit-api::api.tokens.create.no_abilities'))->send();

            $this->halt();
        }

        $expiresIn = (string) ($data['expires_in'] ?? 'never');
        $allowedExpirations = array_map($this->expirationKey(...), $plugin->getTokenExpirationOptions());

        abort_unless(in_array($expiresIn, $allowedExpirations, true), 422);

        $token = $plugin->getTokenRepository()->create(
            $user,
            $panel->getId(),
            (string) ($data['name'] ?? ''),
            $abilities,
            $expiresIn === 'never' ? null : now()->addDays((int) $expiresIn),
            $panel->hasTenancy() ? alfakit()->getTenant() : null,
        );

        if (app()->bound('session')) {
            session()->put(self::NEW_TOKEN_SESSION_KEY, Crypt::encryptString($token->plainTextToken));
        }

        Notification::make()->success()->title(__('alfakit-api::api.tokens.create.created'))->send();
    }

    /**
     * The new token, once, above the list.
     */
    public function content(Schema $schema): Schema
    {
        if ($this->newToken === null) {
            return $schema;
        }

        return $schema->components([
            Callout::make(__('alfakit-api::api.tokens.new.heading'))
                ->description(__('alfakit-api::api.tokens.new.description'))
                ->icon('key-round')
                ->success()
                ->footer([
                    Text::make($this->newToken)
                        ->fontFamily('mono')
                        ->copyable(copyMessage: __('alfakit-api::api.tokens.new.copied')),
                ]),
        ]);
    }

    /**
     * The new token's callout comes before the tokens table.
     */
    protected function getContentBlocks(): array
    {
        $blocks = parent::getContentBlocks();

        usort($blocks, static fn (array $a, array $b): int => (($a['key'] ?? null) === 'content' ? 0 : 1) <=> (($b['key'] ?? null) === 'content' ? 0 : 1));

        return $blocks;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('alfakit-api::api.tokens.table.heading'))
            ->query(fn (): Builder => $this->getTokensQuery())
            ->columns([
                TextColumn::make('name')
                    ->label(__('alfakit-api::api.tokens.table.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('abilities')
                    ->label(__('alfakit-api::api.tokens.table.abilities'))
                    ->getStateUsing(static fn (AccessToken $record): array => $record->getAbilities() === [Abilities::ALL] ? [(string) __('alfakit-api::api.abilities.all')] : $record->getAbilities())
                    ->badge()
                    ->color('gray')
                    ->limitList(4),
                TextColumn::make('last_used_at')
                    ->label(__('alfakit-api::api.tokens.table.last_used'))
                    ->since()
                    ->placeholder(__('alfakit-api::api.tokens.table.never_used'))
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->label(__('alfakit-api::api.tokens.table.expires'))
                    ->dateTime()
                    ->placeholder(__('alfakit-api::api.tokens.fields.never'))
                    ->color(static fn (AccessToken $record): ?string => $record->isExpired() ? 'danger' : null)
                    ->description(static fn (AccessToken $record): ?string => $record->isExpired() ? (string) __('alfakit-api::api.tokens.table.expired') : null)
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('alfakit-api::api.tokens.table.created'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label(__('alfakit-api::api.tokens.revoke.label'))
                    ->icon('trash-2')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('alfakit-api::api.tokens.revoke.heading'))
                    ->modalDescription(static fn (AccessToken $record): string => (string) __('alfakit-api::api.tokens.revoke.description', ['name' => $record->getTokenName()]))
                    ->modalSubmitActionLabel(__('alfakit-api::api.tokens.revoke.submit'))
                    ->authorize(static fn (): bool => static::canAccess())
                    ->action(function (AccessToken $record): void {
                        $user = $this->getPanel()->auth()->user();

                        abort_unless($user !== null && static::getPlugin()->getTokenRepository()->revoke($user, $record->getTokenId()), 404);

                        Notification::make()->success()->title(__('alfakit-api::api.tokens.revoke.revoked'))->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('key-round')
            ->emptyStateHeading(__('alfakit-api::api.tokens.table.empty_heading'))
            ->emptyStateDescription(__('alfakit-api::api.tokens.table.empty_description'));
    }

    /**
     * The signed-in user's tokens on this panel (and tenant), from the plugin's token
     * repository when it can be listed (`QueryableTokenRepository`).
     *
     * @return Builder<covariant Model>
     */
    protected function getTokensQuery(): Builder
    {
        $panel = $this->getPanel();
        $user = $panel->auth()->user();
        $tenant = $panel->hasTenancy() ? alfakit()->getTenant() : null;
        $repository = static::getPlugin()->getTokenRepository();

        if (! $user instanceof Model) {
            return ApiToken::query()->whereRaw('1 = 0');
        }

        if ($repository instanceof QueryableTokenRepository) {
            return $repository->tokensQuery($user, $panel->getId(), $tenant);
        }

        return ApiToken::query()->ownedBy($user, $panel->getId(), $tenant);
    }
}
