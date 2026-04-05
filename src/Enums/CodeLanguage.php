<?php

namespace Martis\Enums;

enum CodeLanguage: string
{
    case Dockerfile = 'dockerfile';
    case HtmlMixed = 'htmlmixed';
    case Javascript = 'javascript';
    case Markdown = 'markdown';
    case Nginx = 'nginx';
    case Php = 'php';
    case Ruby = 'ruby';
    case Sass = 'sass';
    case Shell = 'shell';
    case Sql = 'sql';
    case Twig = 'twig';
    case Vim = 'vim';
    case Vue = 'vue';
    case Xml = 'xml';
    case YamlFrontmatter = 'yaml-frontmatter';
    case Yaml = 'yaml';
}
