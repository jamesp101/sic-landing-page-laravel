<?php

namespace Database\Seeders;

use InvalidArgumentException;

/**
 * Demo content shared by `DatabaseSeeder` and the tests/e2e specs: the Users, Departments, Posts, Hero sections
 * and Members the specs look up by name. Rows refer to each other by key (`department: 'cit'`, `author: 'cit'`,
 * `parent: <member key>`); the seeder resolves the keys to ids. Port of `prisma/demo-data.ts`. Post dates are
 * ISO times with the Asia/Manila offset (Laravel stored these wall-clock times in Manila).
 */
final class DemoData
{
    /** Departments by key (`cit`, `cba`, `coe`). */
    public const DEPARTMENTS = [
        'cit' => [
            'name' => 'College of Information Technology',
            'slug' => 'cit',
            'description' => 'Empowering the next generation of technology leaders through innovative curricula, hands-on programming labs, and industry-partnered software engineering projects.',
            'hero_content' => '# Lead the Digital Frontier

The College of Information Technology at San Isidro College offers state-of-the-art specialization in **Software Engineering**, **Network Administration**, and **Data Analytics**. Our graduates are globally competitive, highly technical, and values-driven professionals.

### Why Choose CIT?
- **Modern Laboratories:** Equipped with high-spec developer environments.
- **Industry Integration:** Internships with top-tier technology firms.
- **Research Focused:** Active publication in artificial intelligence and systems design.',
        ],
        'cba' => [
            'name' => 'College of Business Administration',
            'slug' => 'cba',
            'description' => 'Developing ethical business leaders, entrepreneurs, and financial managers who drive sustainable economic growth and societal progress.',
            'hero_content' => '# Shape the Future of Commerce

At the College of Business Administration, we foster entrepreneurship, strategic marketing, and ethical leadership. We prepare students for the complexities of global trade, digital finance, and corporate management.

### Key Highlights
- **Business Incubator:** Pitch, prototype, and launch your own startup.
- **Case-Study Method:** Learn through real-world corporate challenges.
- **Global Networking:** Mentorship programs with successful alumni business owners.',
        ],
        'coe' => [
            'name' => 'College of Education',
            'slug' => 'coe',
            'description' => 'Nurturing compassionate educators, curriculum developers, and academic leaders dedicated to transformative learning experiences.',
            'hero_content' => '# Inspire Through Transformative Education

The College of Education is the cornerstone of San Isidro College\'s academic legacy. We combine pedagogical theory with extensive classroom practice to shape teachers who inspire and lead.

### Specializations Offered
- **Elementary Education:** Child development and fundamental learning systems.
- **Secondary Education:** Math, Science, English, and Social Studies majors.
- **Special Needs Education:** Inclusive teaching and learning strategies.',
        ],
    ];

    /** Users by key: `department` is a Department key or null (the Superadmin). Passwords come from `config('sic.seed')`. */
    public const USERS = [
        'superAdmin' => [
            'name' => 'Dr. Maria Clara (Super Admin)',
            'email' => 'admin@sic.edu.ph',
            'role' => 'superadmin',
            'department' => null,
        ],
        'cit' => [
            'name' => 'Prof. Juan Dela Cruz (CIT Admin)',
            'email' => 'cit@sic.edu.ph',
            'role' => 'department_user',
            'department' => 'cit',
        ],
        'cba' => [
            'name' => 'Dr. Jose Rizal (CBA Admin)',
            'email' => 'cba@sic.edu.ph',
            'role' => 'department_user',
            'department' => 'cba',
        ],
    ];

    /** The Welcome hero: the rich content at the top of the home page. */
    public const WELCOME_HERO = '# Welcome to San Isidro College

<Center>
Empowering minds, cultivating values, and inspiring service since 1949.
</Center>

<Flex>
<Card title="Academic Programs">
Explore our comprehensive collegiate courses and find the perfect path for your future career.
</Card>
<Card title="Campus Life">
Join student organizations, varsity sports, and artistic groups to build lifelong connections.
</Card>
</Flex>

<Accordion title="Learn More About SIC">
San Isidro College is a Catholic diocesan educational institution located in Malaybalay City, Bukidnon, Philippines. We are committed to developing students with strong values, academic excellence, and a passion for community service.
</Accordion>

<Badges>Values, Excellence, Service, Innovation</Badges>';

    /** The heading and the quote of the AI lab post's Markdown, which the e2e specs look for on the page and in the editor. */
    public const AI_LAB_HEADING = 'Leading the Future of AI';

    public const AI_LAB_QUOTE = 'This laboratory provides our software engineering students with the computing power needed to solve real-world problems using machine learning.';

    /** The sub-links of the seeded Downloads navigation link, in order. */
    public const DOWNLOAD_NAV_CHILDREN = ['Student Handbook', 'Academic Calendars'];

    /** Posts by key; `author` is a User key, `department` a Department key or null. */
    public const POSTS = [
        'enrolment' => [
            'title' => 'SIC Enrolment Guide for First Semester 2026-2027',
            'content' => '# Complete Enrolment Steps

Welcome to San Isidro College! Follow this simple guide to complete your registration.

### Step 1: Admissions Review
Submit your transcript, birth certificate, and high school diploma to the Registrar Office.

### Step 2: Faculty Evaluation
Visit your department dean\'s office for academic advising and course scheduling.

### Step 3: Payment & Verification
Pay your tuition fee at the Cashier\'s Desk or online bank transfer, then check your portal.',
            'type' => 'announcement',
            'start_date' => null,
            'end_date' => null,
            'department' => null,
            'author' => 'superAdmin',
            'is_featured' => true,
        ],
        'foundationWeek' => [
            'title' => '74th Foundation Week Celebration',
            'content' => '# Celebrate with Pride!

Our annual Foundation Week features sports events, music festivals, food trucks, and academic debates.

### Schedule of Key Events
- **August 15:** Grand Opening Parade (8:00 AM)
- **August 17:** College Quiz Bee & IT Hackathon Exhibition
- **August 19:** Alumni Dinner Dance & Gala Concert
- **August 20:** Awarding Ceremony & Fireworks Display',
            'type' => 'event',
            'start_date' => '2026-08-15T08:00:00+08:00',
            'end_date' => '2026-08-20T22:00:00+08:00',
            'department' => null,
            'author' => 'superAdmin',
            'is_featured' => true,
        ],
        'aiLab' => [
            'title' => 'CIT Unveils New AI Research Laboratory',
            'content' => '# Leading the Future of AI

The College of Information Technology is excited to announce the official opening of the AI Research Lab. Sponsored by local tech alumni, the lab houses top-tier GPUs to support student research in computer vision, large language models, and IoT automation.

Dean Del Cruz expressed his excitement:
> "This laboratory provides our software engineering students with the computing power needed to solve real-world problems using machine learning."',
            'type' => 'article',
            'start_date' => null,
            'end_date' => null,
            'department' => 'cit',
            'author' => 'cit',
            'is_featured' => false,
        ],
        'quantumSeminar' => [
            'title' => 'CIT Tech Seminar: Quantum Computing Basics',
            'content' => '# Join the Seminar!

Learn the fundamentals of quantum gates, superposition, and next-generation cryptography in this introductory seminar led by guest speakers from global technology firms.

### Details
- **Room:** CIT Lab 3
- **Duration:** 3 Hours
- **Prerequisites:** Basic understanding of algebra and Boolean logic.',
            'type' => 'event',
            'start_date' => '2026-07-20T13:00:00+08:00',
            'end_date' => '2026-07-20T16:00:00+08:00',
            'department' => 'cit',
            'author' => 'cit',
            'is_featured' => false,
        ],
        'entrepreneurshipAward' => [
            'title' => 'CBA Students Win National Entrepreneurship Award',
            'content' => '# Championing Innovation

A team of three senior Business Administration students won first place at the National Business Innovation Pitch for their sustainable urban agriculture platform. The startup secures seed funding and office space inside the SIC Business Incubator.

### Winning Team Members
- **Juan Luna** (Finance)
- **Ana Maria** (Marketing)
- **Luis Miguel** (Operations)',
            'type' => 'article',
            'start_date' => null,
            'end_date' => null,
            'department' => 'cba',
            'author' => 'cba',
            'is_featured' => false,
        ],
    ];

    /** Hero sections by key. */
    public const HERO_SECTIONS = [
        'welcome' => [
            'title' => 'Welcome Introduction',
            'content' => '# Welcome to San Isidro College

<Center>
Empowering minds, cultivating values, and inspiring service since 1949.
</Center>',
            'order' => 1,
            'is_active' => true,
            'department' => null,
        ],
        'campusLife' => [
            'title' => 'Academic & Campus Life Blocks',
            'content' => '<Flex>
<Card title="Academic Programs">
Explore our comprehensive collegiate courses and find the perfect path for your future career.
</Card>
<Card title="Campus Life">
Join student organizations, varsity sports, and artistic groups to build lifelong connections.
</Card>
</Flex>

<Accordion title="Learn More About SIC">
San Isidro College is a Catholic diocesan educational institution located in Malaybalay City, Bukidnon, Philippines. We are committed to developing students with strong values, academic excellence, and a passion for community service.
</Accordion>

<Badges>Values, Excellence, Service, Innovation</Badges>',
            'order' => 2,
            'is_active' => true,
            'department' => null,
        ],
        'cit' => [
            'title' => 'CIT Tech Spotlight',
            'content' => '# Lead the Digital Frontier

<Center>
The College of Information Technology at San Isidro College offers state-of-the-art specialization in **Software Engineering**, **Network Administration**, and **Data Analytics**.
</Center>

<Flex>
<Card title="Modern Laboratories">
Equipped with high-spec developer environments and active publication in AI.
</Card>
<Card title="Industry Integration">
Internships with top-tier technology firms and coding hackathons.
</Card>
</Flex>',
            'order' => 1,
            'is_active' => true,
            'department' => 'cit',
        ],
        'cba' => [
            'title' => 'CBA Commerce Highlight',
            'content' => '# Shape the Future of Commerce

<Center>
Developing ethical business leaders, entrepreneurs, and financial managers.
</Center>

<Flex>
<Card title="Business Incubator">
Pitch, prototype, and launch your own startup with mentor support.
</Card>
<Card title="Case-Study Method">
Learn through real-world corporate challenges and strategic marketing.
</Card>
</Flex>',
            'order' => 1,
            'is_active' => true,
            'department' => 'cba',
        ],
    ];

    /** Members by key; `parent` is the key of another Member of the same Department, listed before this one. */
    public const MEMBERS = [
        'citDean' => [
            'name' => 'Prof. Juan Dela Cruz',
            'role' => 'Dean, College of IT',
            'department' => 'cit',
            'parent' => null,
            'order' => 1,
        ],
        'citCsHead' => [
            'name' => 'Dr. Alan Turing',
            'role' => 'Head of Computer Science',
            'department' => 'cit',
            'parent' => 'citDean',
            'order' => 1,
        ],
        'citIsHead' => [
            'name' => 'Dr. Grace Hopper',
            'role' => 'Head of Information Systems',
            'department' => 'cit',
            'parent' => 'citDean',
            'order' => 2,
        ],
        'citInstructor' => [
            'name' => 'Ada Lovelace',
            'role' => 'Software Engineering Instructor',
            'department' => 'cit',
            'parent' => 'citCsHead',
            'order' => 1,
        ],
        'citWebInstructor' => [
            'name' => 'Tim Berners-Lee',
            'role' => 'Web Technologies Instructor',
            'department' => 'cit',
            'parent' => 'citIsHead',
            'order' => 1,
        ],
        'cbaDean' => [
            'name' => 'Dr. Jose Rizal',
            'role' => 'Dean, College of Business',
            'department' => 'cba',
            'parent' => null,
            'order' => 1,
        ],
        'cbaChair' => [
            'name' => 'Adam Smith',
            'role' => 'Economics Department Chair',
            'department' => 'cba',
            'parent' => 'cbaDean',
            'order' => 1,
        ],
    ];

    /**
     * Seeding writes demo Users with known Passwords: only a local database is accepted unless
     * `SEED_ALLOW_REMOTE=1` (`config('sic.seed.allow_remote')`).
     *
     * @param  array{driver?: string, host?: string|null}  $connection  the default connection's config
     */
    public static function assertLocalDatabase(array $connection, bool $allowRemote): void
    {
        if ($allowRemote || ($connection['driver'] ?? '') === 'sqlite') {
            return;
        }
        $host = (string) ($connection['host'] ?? '');
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            return;
        }
        throw new InvalidArgumentException(
            'Refusing to seed '.($host !== '' ? "the database on {$host}" : 'a database that is not local').'. '
            .'Set SEED_ALLOW_REMOTE=1 if that is really intended.',
        );
    }

    /** The Password of a seeded User: `SEED_SUPERADMIN_PASSWORD` / `SEED_DEPARTMENT_PASSWORD`, else `password` (never defaulted in production). */
    public static function seedPassword(string $userKey): string
    {
        $name = $userKey === 'superAdmin' ? 'superadmin_password' : 'department_password';
        $value = (string) config("sic.seed.{$name}");
        if ($value !== '') {
            return $value;
        }
        if (app()->isProduction()) {
            throw new InvalidArgumentException(strtoupper("SEED_{$name}").' must be set when seeding in production');
        }

        return 'password';
    }
}
