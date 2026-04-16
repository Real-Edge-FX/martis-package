# Panels e Tabs

Martis suporta dois mecanismos de layout avançado para organizar campos em formulários
e páginas de detalhe: **Panels** e **Tabs**. Ambos são inspirados diretamente no
[Laravel Nova 5](https://nova.laravel.com/docs/v5/resources/panels) e seguem a mesma
ergonomia de API.

---

## Panels

Um `Panel` agrupa campos relacionados numa secção visual com título, fundo diferenciado
e separador horizontal. Pode ser recolhido pelo utilizador e suporta limite de campos
visíveis antes de um botão "Show more".

### API

```php
use Martis\Layout\Panel;

Panel::make('Título do Panel', [
    // campos...
])
->collapsible()        // opcional — permite recolher
->collapsedByDefault() // opcional — começa recolhido (implica collapsible)
->limit(int $n)        // opcional — mostra apenas os primeiros N campos
```

### Exemplos

#### Panel básico

O caso mais simples: agrupa campos num contentor visual com título. O utilizador
não pode recolher.

```php
Panel::make('Publicação', [
    Select::make('status')
        ->options(['draft', 'published', 'archived'])
        ->required(),

    DateTime::make('published_at', 'Published At')
        ->nullable(),
]),
```

#### Panel collapsible

O utilizador pode clicar no cabeçalho para recolher ou expandir o panel.
Começa expandido por omissão.

```php
Panel::make('Autor & Categoria', [
    BelongsTo::make('category')
        ->relatedResource('categories')
        ->nullable(),

    BelongsTo::make('user', 'Author')
        ->relatedResource('users')
        ->nullable(),
])->collapsible(),
```

#### Panel collapsedByDefault

Começa recolhido. Útil para campos avançados ou raramente editados — ficam
fora do caminho sem desaparecer completamente.

```php
Panel::make('Conteúdo Avançado', [
    Markdown::make('excerpt', 'Excerpt')->nullable(),
    Textarea::make('body')->nullable(),
])->collapsedByDefault(),
```

#### Panel com limit

Mostra apenas os primeiros N campos. Os restantes ficam atrás de um botão
"Show more / Show less". Ideal quando um panel tem muitos campos e queremos
reduzir o scroll inicial.

```php
Panel::make('Tags & Etiquetas', [
    Tag::make('tags', 'Tags')
        ->relatedResource('tags'),

    MultiSelect::make('labels', 'Labels')
        ->options(['featured', 'trending', 'exclusive']),

    Text::make('source_url', 'Source URL')
        ->nullable(),
])->limit(1), // só mostra o primeiro campo inicialmente
```

### Onde usar Panels

Panels podem aparecer em:

- `fieldsForCreate()`
- `fieldsForUpdate()`
- `fieldsForDetail()`
- **Dentro de Tabs** (ver secção abaixo)

Não aparecem em `fields()` (index) — nesse contexto os campos são sempre achatados.

---

## Tabs

Tabs organizam campos e panels em abas navegáveis. São úteis para resources complexas
com muitos campos, permitindo agrupar temas diferentes (Geral, Conteúdo, Organização,
Avançado) sem sobrecarregar o formulário.

### Estrutura

```
TabGroup          ← contentor de topo (implements LayoutContract)
  └── Tab         ← aba individual
        ├── Field ← campo directo dentro da aba
        └── Panel ← panel aninhado dentro da aba
```

### API

```php
use Martis\Layout\Tab;
use Martis\Layout\TabGroup;

TabGroup::make([
    Tab::make('Nome da Aba', [
        // campos e/ou panels...
    ]),
    Tab::make('Outra Aba', [
        // campos e/ou panels...
    ]),
])
```

### Exemplos

#### Tabs simples (só campos)

```php
TabGroup::make([
    Tab::make('Geral', [
        Text::make('title')->required(),
        Select::make('status')->options(['draft', 'published']),
        DateTime::make('published_at')->nullable(),
    ]),

    Tab::make('Conteúdo', [
        Markdown::make('excerpt')->nullable(),
        Textarea::make('body')->nullable(),
    ]),
]),
```

#### Tabs com Panels aninhados

Cada `Tab` pode conter um ou mais `Panel`. Os panels dentro de tabs funcionam
independentemente — um panel collapsible dentro de uma aba pode ser recolhido
sem afectar as outras abas.

```php
TabGroup::make([
    Tab::make('Organização', [
        Panel::make('Relações', [
            BelongsTo::make('category')->relatedResource('categories'),
            BelongsTo::make('user', 'Author')->relatedResource('users'),
        ])->collapsible(),

        Tag::make('tags', 'Tags')->relatedResource('tags'),
    ]),

    Tab::make('Avançado', [
        Panel::make('SEO & Referências', [
            Text::make('source_url', 'Source URL')->nullable(),
        ])->collapsedByDefault(),
    ]),
]),
```

#### TabGroup completo (exemplo de uso real)

```php
public function fieldsForUpdate(Request $request): array
{
    return [
        TabGroup::make([
            Tab::make('Geral', [
                Text::make('title')->required(),
                Badge::make('status')->addTypes([
                    'published' => 'success',
                    'draft'     => 'warning',
                    'archived'  => 'danger',
                ]),
                DateTime::make('published_at', 'Published At')->nullable(),
            ]),

            Tab::make('Conteúdo', [
                Markdown::make('excerpt', 'Excerpt')->nullable(),
                Textarea::make('body')->nullable(),
            ]),

            Tab::make('Organização', [
                Panel::make('Relações', [
                    BelongsTo::make('category')->relatedResource('categories')->nullable(),
                    BelongsTo::make('user', 'Author')->relatedResource('users')->nullable(),
                ])->collapsible(),
                Tag::make('tags', 'Tags')->relatedResource('tags')->withPreview(),
                MultiSelect::make('labels', 'Labels')
                    ->options(['featured', 'trending', 'exclusive'])
                    ->nullable(),
            ]),

            Tab::make('Avançado', [
                Panel::make('SEO & Referências', [
                    Text::make('source_url', 'Source URL')->nullable(),
                ])->collapsedByDefault(),
            ]),
        ]),
    ];
}
```

### Onde usar TabGroup

TabGroup pode aparecer em:

- `fieldsForCreate()`
- `fieldsForUpdate()`
- `fieldsForDetail()`

Não aparece em `fields()` (index) — os campos são sempre achatados para a listagem.

---

## Combinações possíveis

| Contexto           | Panel | TabGroup | Tab dentro de TabGroup | Panel dentro de Tab |
|--------------------|-------|----------|------------------------|---------------------|
| `fields()` (index) | ✗     | ✗        | ✗                      | ✗                   |
| `fieldsForCreate`  | ✅    | ✅       | ✅                     | ✅                  |
| `fieldsForUpdate`  | ✅    | ✅       | ✅                     | ✅                  |
| `fieldsForDetail`  | ✅    | ✅       | ✅                     | ✅                  |

---

## Comparação com Laravel Nova 5

| Feature                    | Laravel Nova 5              | Martis                             |
|----------------------------|-----------------------------|------------------------------------|
| Panel básico               | `Panel::make('T', [...])`   | `Panel::make('T', [...])`          |
| Panel collapsible          | `->collapsible()`           | `->collapsible()`                  |
| Panel collapsed by default | `->collapsedByDefault()`    | `->collapsedByDefault()`           |
| Panel com limite           | `->limit(n)`                | `->limit(n)`                       |
| Tab individual             | `Tab::make('T', [...])`     | `Tab::make('T', [...])`            |
| Agrupamento de tabs        | `Tab::group([...])`         | `TabGroup::make([...])`            |
| Tabs com panels            | ✅ suportado                | ✅ suportado                       |
| Tabs com relationships     | ✅ suportado                | ✅ (via campos BelongsTo/Tag/etc.) |

> **Diferença de API:** Nova 5 usa `Tab::group(...)` como método estático da classe `Tab`.
> Martis usa `TabGroup::make(...)` como uma classe separada. A semântica é equivalente.

---

## Showcase no Playground

O resource **Layout Showcase** em `playground/app/Martis/Resources/LayoutShowcaseResource.php`
demonstra todas as combinações descritas neste documento:

- **Create** — demonstra os 5 tipos de Panel (básico, collapsible, collapsedByDefault, limit, campos fora de panel)
- **Edit** — demonstra TabGroup com 4 abas (Geral, Conteúdo, Organização com Panel aninhado, Avançado)
- **Detail** — usa a mesma estrutura do Edit

Para aceder ao showcase, navegar para `/showcase/layout-showcase/create` e `/showcase/layout-showcase/{id}/edit` no playground.

---

## Serialização JSON

O backend serializa Panels e TabGroups como parte do schema de fields da resource.
O formato é estável e pode ser inspeccionado via `GET /api/{resource}/schema`.

### Panel

```json
{
  "type": "panel",
  "title": "Publicação",
  "collapsible": false,
  "collapsedByDefault": false,
  "limit": null,
  "fields": [
    { "type": "select", "attribute": "status", ... },
    { "type": "datetime", "attribute": "published_at", ... }
  ]
}
```

### TabGroup

```json
{
  "type": "tab_group",
  "tabs": [
    {
      "type": "tab",
      "title": "Geral",
      "fields": [
        { "type": "text", "attribute": "title", ... }
      ]
    },
    {
      "type": "tab",
      "title": "Organização",
      "fields": [
        {
          "type": "panel",
          "title": "Relações",
          "fields": [ ... ]
        }
      ]
    }
  ]
}
```
