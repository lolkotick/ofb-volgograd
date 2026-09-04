/** Навигация сайта.
 *  Список направлений — единый источник: data/sections.json.
 *  Раздел с published: false остаётся в меню, но ведёт на страницу «Раздел готовится». */
import sectionsData from '../../data/sections.json';

export const sections = sectionsData.sections ?? [];

export const publishedSections = sections.filter((s) => s.published);

export const nav = [
  { href: '/',          title: 'Главная' },
  { href: '/about/',    title: 'О федерации' },
  { href: '/news/',     title: 'Новости' },
  { href: '/calendar/', title: 'Календарь' },
  {
    title: 'Направления',
    children: sections.map((s) => ({
      href: `/sections/${s.slug}/`,
      title: s.title,
      stub: !s.published,
    })),
  },
  { href: '/documents/', title: 'Документы' },
  { href: '/contacts/',  title: 'Контакты' },
];
