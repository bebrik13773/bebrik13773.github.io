# Future Roadmap

## Ближайшие улучшения

- Достижения и награды за прогресс:
  серии кликов, milestones по счету, редкие награды из `Летающего бобра`, визуальные медали в профиле.
- Новые биомы и погода в `Летающем бобре`:
  дневной лес, туманный вечер, дождь, ветер и редкие атмосферные события без смены базовой механики.
- Replay и forensic viewer:
  просмотр сохраненного client-log как таймлайна для разбора апелляций и поиска багов.
- Расширенный профиль игрока:
  статистика кликов, история рекордов, любимые скины, активные сессии и красивое summary по аккаунту.
- Больше настраиваемых эффектов и accessibility:
  сила ripple, контраст UI, крупные шрифты, упрощенный режим интерфейса, отдельные параметры для motion и вибрации.

## Подробные Промты Для Генерации Графики

Ниже отдельный блок промтов для внешней нейросети. Цель: получить аккуратные, согласованные между собой ассеты для `Летающего бобра` и связанного UI, чтобы потом их можно было легко встроить в игру.

### Общие требования ко всем ассетам

- Стиль:
  яркий, читаемый, мультяшный 2D game art, friendly casual mobile game, не реализм, не 3D render, не low-poly, не пиксель-арт.
- Камера:
  только боковой вид `side view`, без сильной перспективы, без изометрии.
- Читаемость:
  силуэт должен быть понятен даже в маленьком размере на телефоне.
- Контур:
  аккуратный, чистый, без грязных рваных краев, без лишних мелких деталей, которые распадутся при уменьшении.
- Фон:
  если это отдельный спрайт, то фон обязательно прозрачный.
- Композиция:
  объект по центру, не обрезан, с запасом воздуха по краям.
- Свет:
  мягкий, сказочный, слегка влажный лесо-болотный вайб, но без мрачного хоррора.
- Палитра:
  болотные зеленые, теплые коричневые, мягкие бирюзовые и голубые акценты, чуть золотистого света.
- Формат результата:
  ideally `PNG` или `SVG-ready` look, квадратный холст `2048x2048` для одиночных объектов, прозрачный фон.
- Важное:
  без текста, без интерфейсных надписей, без водяных знаков, без рамок, без лишних персонажей.

### Общий negative prompt для большинства ассетов

```text
photorealistic, realistic fur, realistic wood texture, horror, creepy, dirty composition, cropped subject, cut off tail, cut off ears, cut off feet, heavy perspective, isometric, top view, front view, complex background, text, letters, watermark, logo, frame, ui, blurry silhouette, overdetailed noise, low contrast subject, dark unreadable art, extra limbs, malformed anatomy
```

### Базовый стиль-гайд для всей серии

```text
Create a cohesive set of 2D side-view mobile game assets for a whimsical swamp-forest arcade game. Style: polished cartoon casual game art, readable at small size, soft painterly shading, clean silhouette, transparent background for single objects, cohesive palette of moss green, wet wood brown, muted teal, soft sky blue, warm amber highlights. The world should feel like a magical forest swamp: gentle mist, reeds, moss, damp bark, shallow glowing water, but still bright and friendly. Avoid realism, avoid heavy detail, avoid horror mood. Every asset must look like it belongs to the same game and same artist.
```

### 1. Главный промт для бобра, чтобы он выглядел именно как бобер

```text
Cute flying beaver for a 2D side-view casual arcade game, full body visible, transparent background, centered composition, clean cartoon silhouette, unmistakably a beaver and not a seal. Short rounded body, small ears, visible front teeth, expressive eyes, tiny paws tucked near body, large flat beaver tail clearly visible, soft brown fur with lighter beige belly and muzzle, subtle whiskers, friendly determined expression, readable shape for mobile game, high clarity, polished 2D game art, side view only, no extra objects, no crop, no text.
```

### 2. Бобер: idle / парение перед стартом

```text
2D side-view cartoon beaver for a mobile arcade game, idle hovering pose, transparent background, same character design as the main beaver asset, body relaxed, tail slightly lowered, paws gently tucked, eyes open and alert, small smile, soft magical swamp-forest palette, clean silhouette, polished casual game sprite, centered with padding, no text, no background.
```

### 3. Бобер: glide / спокойный полет

```text
2D side-view cartoon beaver gliding through the air for a casual mobile game, transparent background, same exact beaver character, streamlined pose, body stretched slightly forward, tail balancing behind, paws tucked, calm focused eyes, readable game silhouette, polished cartoon shading, friendly swamp-forest style, no crop, centered, no text, no background.
```

### 4. Бобер: flap-up / активный взмах вверх

```text
2D side-view cartoon beaver doing an energetic upward flap for a mobile arcade game, transparent background, same exact beaver character, body slightly tilted upward, cheeks and expression more determined, paws lifted a little, tail angled for balance, motion-friendly pose, readable silhouette, polished casual game art, bright friendly swamp palette, centered sprite, no crop, no text, no background.
```

### 5. Бобер: flap-down / переход после взмаха

```text
2D side-view cartoon beaver in downward flap transition for a casual mobile game, transparent background, same character, body slightly compressed, tail stabilizing motion, paws closer to body, eyes focused, strong readable silhouette, polished colorful game art, swamp-forest palette, centered with full body visible, no extra objects, no text, no background.
```

### 6. Бобер: fall / hit

```text
2D side-view cartoon beaver falling after a hit in a casual mobile game, transparent background, same exact beaver character, slightly dizzy or shocked expression but still cute, body tilted downward, tail lagging behind, paws loose, readable silhouette, polished 2D game art, no gore, no damage, no realism, centered, no crop, no text, no background.
```

### 7. Набор деревьев для болота

#### 7.1. Болотная ива

```text
Stylized swamp willow tree for a 2D side-view mobile game, full tree visible, transparent background, elegant drooping branches, mossy bark, soft hanging foliage, readable silhouette, whimsical forest-swamp mood, clean cartoon art, not realistic, not striped, not broken, no text, centered, no background.
```

#### 7.2. Плотная хвойная ель

```text
Stylized forest spruce tree for a 2D side-view casual mobile game, transparent background, dense layered needles, clean triangular silhouette, soft moss hints near lower trunk, readable at small size, polished cartoon art, same swamp-forest palette, no realism, no text, no background.
```

#### 7.3. Сухое болотное дерево

```text
Stylized dead swamp tree for a 2D side-view arcade game, transparent background, twisted but readable silhouette, few bare branches, old damp bark, slightly spooky but still family-friendly, consistent with whimsical swamp forest style, clean shape, no stripes, no realistic decay gore, no text, no background.
```

#### 7.4. Береза без полосатого мусора

```text
Stylized birch tree for a 2D side-view mobile game, transparent background, clean elegant silhouette, white bark with only subtle tasteful birch markings, not noisy, not stripe-heavy, fresh green crown, readable and cohesive with swamp forest setting, polished cartoon art, no text, no background.
```

### 8. Брёвна-препятствия

#### 8.1. Обычное бревно

```text
Top or bottom obstacle log for a 2D side-view casual mobile game, transparent background, thick forest log with readable bark texture, soft moss patches, visible tree rings on cut edge, polished cartoon art, clean silhouette, friendly swamp-forest palette, no heavy shadow baked outside the object, no text.
```

#### 8.2. Подгнившее бревно

```text
Rotten obstacle log for a 2D side-view mobile game, transparent background, same overall size and silhouette family as the normal log, slightly darker damp bark, moss and softened rotten wood details, readable cut rings, still clean and game-friendly, not gross, not hyperrealistic, no text.
```

#### 8.3. Треснувшее или сломанное бревно

```text
Cracked broken forest log obstacle for a 2D side-view casual arcade game, transparent background, same family as the normal game log, visible split wood and broken edge details, readable silhouette, whimsical swamp style, polished cartoon shading, no scary realism, no text.
```

### 9. Облака для неба

```text
Soft stylized fantasy clouds for a 2D side-view mobile game, transparent background, rounded layered cloud shapes, gentle highlights, airy readable silhouette, calm fairy-tale forest sky mood, polished cartoon art, no text, no storm, no realism.
```

### 10. Дальний фон: лес и горы

```text
Wide side-view background layer for a whimsical swamp-forest arcade game, no characters, no UI, panoramic composition. Soft distant mountains barely visible through haze, layered forest silhouettes, atmospheric depth, readable horizon line, magical but clear environment, casual mobile game art, painterly cartoon style, cool blue-green and misty teal tones, not too dark, not too detailed, suitable as a far background layer.
```

### 11. Средний фон: болото и лес

```text
Wide side-view midground layer for a whimsical swamp arcade game, panoramic forest swamp scene, reeds, mossy islets, low shrubs, soft fog bands, clustered trees in the distance, readable parallax-friendly composition, polished cartoon mobile game art, bright but swampy, no characters, no UI, no text.
```

### 12. Передний слой: вода, камыш, кочки

```text
Wide side-view foreground layer for a swamp forest mobile game, shallow swamp water, reeds, cattails, floating leaves, small mossy bumps and wet soil edges, clear readable silhouettes, parallax-friendly composition, polished 2D cartoon game art, no characters, no UI, no text.
```

### 13. Болотная вода под полетом

```text
Stylized swamp water strip for a 2D side-view arcade game, readable surface, subtle ripples, dark green-blue water with magical highlights, floating leaves and tiny reflections, clearly dangerous to fall into but still colorful and beautiful, cartoon game art, side view, no text, no extra scene clutter.
```

### 14. Камыш и мелкие декоративные элементы

```text
Set of small swamp decoration assets for a 2D side-view mobile game, transparent background, cattails, reeds, lily pads, moss clumps, tiny fern bundles, soft mushrooms, all in one cohesive whimsical swamp-forest cartoon style, readable at small size, no text, no background.
```

### 15. Промт на полный moodboard сцены, если нужно сначала подобрать стиль

```text
Create a polished moodboard for a cute side-view swamp forest arcade game starring a flying beaver. Show the art direction only: friendly magical swamp, layered trees, soft mist, reeds, mossy logs, shallow dark water, distant mountains, dreamy sky, readable silhouettes, casual mobile game quality, colorful but not childish overload, polished cartoon rendering, atmospheric but bright enough for gameplay. The beaver should feel energetic, charming, stubborn and adventurous. Avoid realism, avoid dark horror swamp, avoid messy noisy vegetation.
```

### Что обязательно дописывать в генератор рядом с любым промтом

- `transparent background`
- `side view`
- `full subject visible`
- `centered composition`
- `mobile game asset`
- `clean silhouette`
- `no text, no watermark, no frame`

### Что потом ещё можно заказать нейросети отдельным пакетом

- иконки достижений;
- погодные версии одного и того же биома;
- праздничные сезонные деревья;
- отдельные эффекты удара, пыли, всплеска воды;
- более дорогие скины для основного кликера в том же стиле.
