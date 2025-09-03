import os
from openai import OpenAI

# Initialize the OpenAI client
client = OpenAI()

# Verify API key is set
if not os.getenv('OPENAI_API_KEY'):
    print("\n\033[91mBłąd: Nie znaleziono klucza API OpenAI. Proszę upewnić się, że zmienna środowiskowa OPENAI_API_KEY jest ustawiona.\033[0m")
    print("Możesz ustawić klucz API w terminalu komendą:")
    print("export OPENAI_API_KEY='twój-klucz-api'\n")
from typing import List, Optional, Any, Generator
from data import FORMS, TOPICS, TARGETS, INTRO

# Model configuration
MODEL = "gpt-5-mini"
MODEL = "gpt-5-nano"
MODEL = "gpt-5"
MODEL = "gpt-3.5-turbo"
MODEL = "gpt-5-mini"

PRICES_PER_1M = {
    "gpt-5-nano":        {"prompt":  0.05, "completion":  0.40},
    "gpt-4o-mini":       {"prompt":  0.15, "completion":  0.60},
    "gpt-4.1-nano":      {"prompt":  0.20, "completion":  0.80},
    "gpt-5-mini":        {"prompt":  0.25, "completion":  2.00},
    "gpt-4.1-mini":      {"prompt":  0.40, "completion":  1.60},
    "gpt-3.5-turbo":     {"prompt":  0.50, "completion":  1.50},
    "gpt-5":             {"prompt":  1.25, "completion": 10.00},
    "gpt-4.1":           {"prompt":  2.00, "completion":  8.00},
    "gpt-4o":            {"prompt":  2.50, "completion": 10.00},
    "chatgpt-4o-latest": {"prompt":  5.00, "completion": 15.00},
    "gpt-image-1":       {"prompt": 10.00, "completion": 40.00},
}

def __get_prompt(topics: List[str], form_type: str, target: str, name:str, city:str) -> (str, str):
    topics_str = ""

    for topic in topics:
        topics_item = TOPICS[topic]
        topics_str += f"\n\n## {topics_item['title']}\n{topics_item['desc']}\n"
        if topics_item['topics']:
            topics_str += "  - "
            topics_str += "\n  - ".join(topics_item['topics'])

        if form_type == 'proposal':
            topics_str += "\n### Propozycja zmiany przepisów:\n"
            topics_str += topics_item['law']

    system_prompt = "Jesteś mieszkańcem i obywatelem zirytowanym powszechnym łamaniem przepisów związanych z parkowaniem przez kierowców"
    target = TARGETS[target]['title']
    intro = f"\n\n# Pozostałe\n\nMożesz użyć także tych materiałów:\n\n{INTRO}" if form_type != 'email' else ""

    content_prompt = f"""
# Zadanie

Napisz {FORMS[form_type]} do {target} w sprawie wadliwych przepisów regulujących parkowanie w Polsce.

# Uwagi ogólne

Używaj przykładów i propozycji podanych poniżej. Nie wymyślaj własnych propozycji.

Nie stosuj formatowania markdown albo ikon. Czysty tekst.

Podpisz dokument jako:
{name}
{city}

# Szczegóły do poruszenia
{topics_str}

{intro}

# Uwagi końcowe

- Pisz w pierwszej osobie liczby pojedynczej.
- Pisz w stylu oficjalnym, ale nie przesadnie formalnym.
- Pisz krótkie i konkretne zdania.
- Używaj akapitów i wypunktowań.
- Używaj podtytułów do podziału na sekcje.
- Bądź zwięzły i na temat.
"""

    return system_prompt, content_prompt


def generate_complaint_stream(topics: List[str], form_type: str, target: str, name:str, city:str) -> tuple[Generator[str, None, None], float]:
    system_prompt, content_prompt = __get_prompt(topics, form_type, target, name, city)
    
    # Estimate price (roughly 1 token ~ 4 characters for English, a bit less for Polish)
    input_tokens = len(system_prompt) // 3 + len(content_prompt) // 3
    estimated_price = (input_tokens * PRICES_PER_1M[MODEL]['prompt']) / 1_000_000

    # Create a generator that will stream the response
    def response_generator():
        try:
            response = client.chat.completions.create(
                model=MODEL,
                messages=[
                    {"role": "system", "content": system_prompt},
                    {"role": "user", "content": content_prompt}
                ],
                stream=True
            )

            for chunk in response:
                if hasattr(chunk, 'choices') and len(chunk.choices) > 0:
                    delta = chunk.choices[0].delta
                    if hasattr(delta, 'content') and delta.content is not None:
                        content_chunk = delta.content
                        yield content_chunk

        except Exception as e:
            yield f"\n\nBłąd podczas generowania odpowiedzi: {str(e)}"

    return response_generator(), estimated_price

def generate_complaint(topics: List[str], form_type: str, target: str, name:str, city:str, model:str) -> (str, float):
    system_prompt, content_prompt = __get_prompt(topics, form_type, target, name, city)
    
    response = client.chat.completions.create(
        model=model,
        messages=[
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": content_prompt}
        ]
    )
    
    content = response.choices[0].message.content
    
    price = get_price(response.usage)
    return content, price
    

def get_price(usage: Optional[Any]) -> float:
    """Calculate the price based on token usage."""
    if not usage:
        print(f"Can't estimate cost for model {MODEL} - no usage returned by API")
        return 0.0

    # Handle both old and new response formats
    if hasattr(usage, 'prompt_tokens') and hasattr(usage, 'completion_tokens'):
        input_tokens = usage.prompt_tokens
        output_tokens = usage.completion_tokens
    elif hasattr(usage, 'input_tokens') and hasattr(usage, 'output_tokens'):
        input_tokens = usage.input_tokens
        output_tokens = usage.output_tokens
    else:
        print(f"Warning: Could not determine token usage from response: {usage}")
        return 0.0

    pricing = PRICES_PER_1M.get(MODEL)
    if not pricing:
        raise ValueError(f"Missing pricing for model {MODEL}")

    prompt_price = pricing.get("prompt", 0)
    completion_price = pricing.get("completion", 0)

    if not (isinstance(prompt_price, (int, float)) and 
            isinstance(completion_price, (int, float))):
        raise ValueError("Invalid pricing configuration")

    return (input_tokens * prompt_price + output_tokens * completion_price) / 1_000_000

