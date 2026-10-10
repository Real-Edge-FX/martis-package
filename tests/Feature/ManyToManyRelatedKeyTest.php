<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsToMany;
use Martis\Fields\MorphToMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * A many-to-many relationship whose pivot stores another column of the
 * related record than its primary key (`relatedKey`). The attach, the pivot
 * update and the detach wrote and looked up the pivot row by the primary
 * key, so they attached the wrong value, and the detach of an attached
 * record answered 200 and removed nothing (v2.11.1).
 */

class MMRKCourse extends Model
{
    protected $table = 'mmrk_courses';

    protected $guarded = [];

    public $timestamps = false;

    public function skills(): EloquentBelongsToMany
    {
        return $this->belongsToMany(MMRKSkill::class, 'mmrk_course_skill', 'course_id', 'skill_code', 'id', 'code')
            ->withPivot(['note']);
    }

    public function topics(): EloquentMorphToMany
    {
        return $this->morphToMany(MMRKSkill::class, 'subject', 'mmrk_skillables', 'subject_id', 'skill_code', 'id', 'code')
            ->withPivot(['note']);
    }
}

class MMRKSkill extends Model
{
    protected $table = 'mmrk_skills';

    protected $guarded = [];

    public $timestamps = false;
}

class MMRKSkillResource extends Resource
{
    public static function model(): string
    {
        return MMRKSkill::class;
    }

    public static function uriKey(): string
    {
        return 'mmrk-skills';
    }

    public function fields(Request $request): array
    {
        return [Text::make('code')];
    }
}

class MMRKCourseResource extends Resource
{
    public static function model(): string
    {
        return MMRKCourse::class;
    }

    public static function uriKey(): string
    {
        return 'mmrk-courses';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsToMany::make('Skills', 'skills')
                ->relatedResource('mmrk-skills')
                ->fields(fn () => [Text::make('note', 'Note')->nullable()]),
            MorphToMany::make('Topics', 'topics')
                ->relatedResource('mmrk-skills')
                ->fields(fn () => [Text::make('note', 'Note')->nullable()]),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['mmrk_skillables', 'mmrk_course_skill', 'mmrk_skills', 'mmrk_courses'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('mmrk_courses', function ($t) {
        $t->id();
        $t->string('name');
    });
    Schema::create('mmrk_skills', function ($t) {
        $t->id();
        $t->string('code')->unique();
    });
    Schema::create('mmrk_course_skill', function ($t) {
        $t->unsignedBigInteger('course_id');
        $t->string('skill_code');
        $t->string('note')->nullable();
    });
    Schema::create('mmrk_skillables', function ($t) {
        $t->string('subject_type');
        $t->unsignedBigInteger('subject_id');
        $t->string('skill_code');
        $t->string('note')->nullable();
    });

    // Ids and codes do not line up, so a lookup by the wrong column misses.
    MMRKSkill::query()->insert([['id' => 7, 'code' => 'php'], ['id' => 8, 'code' => 'sql']]);
    $this->course = MMRKCourse::query()->create(['name' => 'Laravel']);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(MMRKSkillResource::class);
    $registry->register(MMRKCourseResource::class);
});

it('attaches, updates the pivot of and detaches a record by the relationship relatedKey', function (string $panel, string $table) {
    $base = "/martis/api/resources/mmrk-courses/{$this->course->id}/{$panel}";

    $this->postJson("{$base}/attach", ['related_id' => 7, 'note' => 'first'])->assertCreated();
    expect(DB::table($table)->pluck('skill_code')->all())->toBe(['php']);

    $this->putJson("{$base}/7/pivot", ['note' => 'second'])->assertOk();
    expect(DB::table($table)->value('note'))->toBe('second');

    $this->postJson("{$base}/attach", ['related_ids' => [8]])->assertCreated();
    expect(DB::table($table)->orderBy('skill_code')->pluck('skill_code')->all())->toBe(['php', 'sql']);

    $this->deleteJson("{$base}/7/detach")->assertOk();
    expect(DB::table($table)->pluck('skill_code')->all())->toBe(['sql']);

    // No longer attached: like a missing id.
    $this->deleteJson("{$base}/7/detach")->assertNotFound();
})->with([
    'belongs-to-many' => ['belongs-to-many/skills', 'mmrk_course_skill'],
    'morph-to-many' => ['morph-to-many/topics', 'mmrk_skillables'],
]);
