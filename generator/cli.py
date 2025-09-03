import time
from typing import List
from data import FORMS, TOPICS, TARGETS
from ai import generate_complaint_stream, generate_complaint
from utils import get_targets_by_form, wrap_long_lines


# ANSI color codes for terminal output
WHITE = '\033[1;37m'
GRAY = '\033[90m'
RESET = '\033[0m'

def main() -> None:
    """Run the complaint generator application."""
    name = "Szymon Nieradka"
    city = "Szczecin"
    try:        
        topics = select_topics()
        form_type = select_form_type()
        target = select_target(form_type)

        print(f"\nGeneruję {WHITE}{FORMS[form_type]}{RESET} do {WHITE}{TARGETS[target]['title']}{RESET}. Tematy:")
        for topic in topics:
            print(f"{GRAY}- {TOPICS[topic]['title']}{RESET}")

        print("\n")

        models = [
            "gpt-5-nano",
            "gpt-5-mini",
        ]

        
        for model in models:
            
            start_time = time.time()
            response, price = batch(topics, form_type, target, name, city, model)
            end_time = time.time()
            execution_time = end_time - start_time
            print(f"\n{WHITE}{model}\t{execution_time:.2f} s\t{price:.4f} zł{RESET}")
            print(wrap_long_lines(response))
            print("\n")

    except KeyboardInterrupt:
        print("\nPrzerwano działanie programu.")
    except Exception as e:
        print(f"\nWystąpił błąd: {str(e)}")
        raise e

def stream(topics: List[str], form_type: str, target: str, name:str, city:str) -> str:
    # Get the streaming generator and estimated price
    response_generator, estimated_price = generate_complaint_stream(topics, form_type, target, name, city)
    
    # Print the response as it's generated
    full_response = []
    for chunk in response_generator:
        print(chunk, end='', flush=True)
        full_response.append(chunk)
    print("\n")
    print(f"\n{WHITE}Szacowany koszt: ~{(estimated_price*4):.4f} zł{RESET}")
    return ''.join(full_response)

def batch(topics: List[str], form_type: str, target: str, name:str, city:str, model:str) -> (str, float):
    response, price = generate_complaint(topics, form_type, target, name, city, model)
    return response, price

def select_topics() -> List[str]:
    """Prompt user to select topics."""
    print(f"\n{WHITE}Wybierz tematy, które Cię interesują (po przecinku, np. 1,3,5):{RESET}")
    for key, item in TOPICS.items():
        print(f"{GRAY}{key}. {item['title']}{RESET}")
    
    while True:
        topics_input = input(f"\n{WHITE}Wybierz numery tematów (1-{len(TOPICS)}): {RESET}")
        topics = [t.strip() for t in topics_input.split(",") if t.strip() in TOPICS]
        if topics:
            return topics
        print("Proszę wybrać co najmniej jeden poprawny temat.")

def select_form_type() -> str:
    """Prompt user to select a form type."""
    print(f"\n{WHITE}Wybierz typ dokumentu do wygenerowania:{RESET}")
    for i, (form_type, form_desc) in enumerate(FORMS.items(), 1):
        print(f"{GRAY}{i}. {form_desc}{RESET}")
    
    while True:
        try:
            choice = int(input(f"\n{WHITE}Wybierz numer (1-{len(FORMS)}): {RESET}")) - 1
            if 0 <= choice < len(FORMS):
                return list(FORMS.keys())[choice]
            print(f"Proszę wybrać liczbę od 1 do {len(FORMS)}")
        except ValueError:
            print("Proszę wprowadzić poprawny numer.")

def select_target(form_type: str) -> int:
    print(f"\n{WHITE}Wybierz adresata/kę:{RESET}")
    targets = get_targets_by_form(form_type)

    for i, target in enumerate(targets, 1):
        print(f"{GRAY}{i}. {target}{RESET}")
        
    while True:
        try:
            choice = int(input(f"\n{WHITE}Wybierz numer (1-{len(targets)}): {RESET}")) - 1
            if 0 <= choice < len(targets):
                return targets[choice]
            print(f"Proszę wybrać liczbę od 1 do {len(targets)}")
        except ValueError:
            print("Proszę wprowadzić poprawny numer.")

if __name__ == "__main__":
    main()
