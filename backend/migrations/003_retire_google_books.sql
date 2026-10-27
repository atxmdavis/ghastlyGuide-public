UPDATE releases
SET source_name = 'Minotaur Books',
    source_url = 'https://us.macmillan.com/books/9781250334404/somethinginthewalls/',
    confidence = 'official',
    source_rank = 100,
    platform = 'Paperback',
    region = 'US'
WHERE source_name = 'Google Books'
  AND title = 'Something in the Walls'
  AND creator = 'Daisy Pearce'
  AND release_date = '2026-03-31';

UPDATE releases
SET active = 0
WHERE source_name = 'Google Books';
