# Tutorial — Martis Admin Engine (PT-BR)

## Introdução

Martis é um admin engine moderno para Laravel, construído com React, PrimeReact e Tailwind CSS. Este tutorial guia você desde a instalação até a criação de um painel completo.

## Pré-requisitos

- PHP 8.2+
- Laravel 11+ ou 12+
- Node.js 20+
- pnpm 8+
- Um projeto Laravel existente com pelo menos um Model

## 1. Instalação

```bash
composer require martis/martis
php artisan martis:install
```

## 2. Criar Usuário Admin

```bash
php artisan martis:user
```

Siga os prompts para definir nome, email e senha.

## 3. Criar um Resource

Resources são a peça central do Martis. Cada Resource mapeia um Model Eloquent a uma interface CRUD completa.

```bash
php artisan martis:resource PostResource
```

Edite o arquivo gerado em `app/Martis/PostResource.php`:

```php
namespace App\Martis;

use App\Models\Post;
use Illuminate\Http\Request;
use Martis\Fields\Text;
use Martis\Fields\Textarea;
use Martis\Fields\BelongsTo;
use Martis\Fields\DateTime;
use Martis\Fields\Boolean;
use Martis\Fields\Badge;
use Martis\Resource;

class PostResource extends Resource
{
    public static function model(): string
    {
        return Post::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')
                ->sortable()
                ->searchable()
                ->required()
                ->placeholder('Título do post'),

            Textarea::make('body')
                ->hideFromIndex()
                ->rows(6),

            BelongsTo::make('category_id', 'Category')
                ->titleAttribute('name')
                ->searchable(),

            Boolean::make('published'),

            DateTime::make('published_at')
                ->sortable()
                ->nullable(),
        ];
    }
}
```

Acesse `http://localhost:8000/martis` e o resource aparecerá automaticamente no menu.

## 4. Campos por Contexto

O Martis resolve campos de forma contextual. Override métodos específicos para controlar o que aparece em cada tela:

```php
// Mostrar apenas colunas-chave na listagem
public function fieldsForIndex(Request $request): array
{
    return [
        Text::make('title')->sortable(),
        Badge::make('status'),
        DateTime::make('published_at')->sortable(),
    ];
}

// Formulário de criação com campos diferentes
public function fieldsForCreate(Request $request): array
{
    return [
        Text::make('title')->required(),
        Textarea::make('body')->required(),
        BelongsTo::make('category_id', 'Category'),
    ];
}
```

Cadeia de resolução:

| Contexto | Ordem |
|----------|-------|
| Index | `fieldsForIndex()` → `fields()` |
| Detail | `fieldsForDetail()` → `fields()` |
| Create | `fieldsForCreate()` → `fields()` |
| Update | `fieldsForUpdate()` → `fields()` |
| Inline Create | `fieldsForInlineCreate()` → `fieldsForCreate()` → `fields()` |
| Preview | `fieldsForPreview()` → `fields()` |

## 5. Flags de Visibilidade

Controle a visibilidade de campos por contexto com métodos fluentes:

```php
Text::make('slug')
    ->hideWhenCreating();   // oculto no formulário de criação

DateTime::make('created_at')
    ->exceptOnForms();      // visível em index e detail, oculto em formulários
```

Flags disponíveis: `hideFromIndex()`, `hideFromDetail()`, `hideWhenCreating()`, `hideWhenUpdating()`, `onlyOnIndex()`, `onlyOnDetail()`, `onlyOnForms()`, `exceptOnForms()`.

## 6. Autorização

Martis integra-se com Laravel Policies. Override os métodos de autorização no Resource:

```php
public function authorizedToCreate(Request $request): bool
{
    return $request->user()->isAdmin();
}

public function authorizedToDelete(Request $request): bool
{
    return $request->user()->hasRole('super-admin');
}
```

## 7. Lifecycle Hooks

Execute lógica custom antes/depois de operações CRUD:

```php
public function beforeSave($model, $request, bool $creating): void
{
    if ($creating) {
        $model->author_id = $request->user()->id;
    }
}

public function afterSave($model, $request, bool $creating): void
{
    if ($creating) {
        // Notificar equipe sobre novo post
    }
}
```

## 8. Customizar a Tabela

```php
public static function tableStriped(): bool { return true; }
public static function tableSize(): string { return 'small'; }
public static function perPage(): int { return 50; }
public static function perPageOptions(): array { return [25, 50, 100]; }
```

## 9. Campos Custom

Crie campos com scaffolding PHP + React:

```bash
php artisan martis:field RatingField
```

Isso gera a classe PHP e o componente React correspondente.

## 10. Temas

Crie um tema custom:

```bash
php artisan martis:theme
```

Alterne entre dark/light mode via configuração:

```php
// config/martis.php
'theme' => [
    'default' => 'dark',
    'allowToggle' => true,
],
```

## Próximos Passos

- [Guia de Instalação](installation-guide.md)
- [Referência de Campos](fields.md) — todos os 27 tipos de campo
- [Referência de Resources](resources.md) — configuração e hooks
- [Sistema de Override](overrides.md) — customizar componentes sem fork
- [API Overview](api/overview.md) — endpoints da API
