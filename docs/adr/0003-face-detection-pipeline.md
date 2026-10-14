# 0003 — Detekcja i rozmywanie twarzy

**Status:** obowiązuje (zastąpiło lokalny model dlib)

## Kontekst
Zdjęcia kontekstowe trafiają do publicznej galerii i na Tumblr, a mogą zawierać twarze.
Twarze są na ~0,5% zdjęć, więc precyzyjne (droższe) API dla każdego zdjęcia to strata pieniędzy.

## Decyzja
- `face-detect-consumer` (kolejka zasilana z `RecydywaStore::update()` dla tablic zgłoszonych przez >1 użytkownika)
  wywołuje `src/inc/integrations/FaceDetect.php`:
  1. gpt-5-nano (`OPENAI_FACES_MODEL`, `detail: low`) tylko **liczy** twarze;
  2. dopiero przy `count > 0` — Google Cloud Vision `FACE_DETECTION` (`GOOGLE_VISION_API_KEY`,
     **dedykowany klucz** ograniczony do Cloud Vision — nie używaj kluczy Maps/Firebase).
- Ramki zapisywane znormalizowane (0..1) w `$app->faces->boxes` z `blurred: true`.
- `src/inc/FaceBlur.php` rozmywa tylko te obszary w wariantach publicznych (miniatura galerii, upload na Tumblr).
- **Oryginał `contextImage` to dowód — nigdy nie jest modyfikowany.**
- Google nic nie znalazł / błąd → `blurred: false`, zdjęcie pozostaje całkowicie ukryte.
  Historyczne wyniki dlib z `faces.count > 0` też zostają ukryte.

## Konsekwencje
- Każdy nowy publiczny wariant zdjęcia musi przejść przez `FaceBlur` albo respektować ukrycie.
