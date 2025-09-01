from data import TARGETS


def wrap_long_lines(text: str, width: int = 78, indent: int = 2) -> str:
    """Wrap long lines while preserving existing formatting."""
    wrapped_lines = []
    indent_str = ' ' * indent
    
    for paragraph in text.split('\n\n'):
        if not paragraph.strip():
            wrapped_lines.append('')
            continue
            
        for line in paragraph.split('\n'):
            if len(line) <= width:
                wrapped_lines.append(f"{indent_str}{line}")
            else:
                words = line.split()
                current_line = []
                current_length = 0
                
                for word in words:
                    if current_line and current_length + len(word) + 1 > width - indent:
                        wrapped_lines.append(f"{indent_str}{' '.join(current_line)}")
                        current_line = [word]
                        current_length = len(word)
                    else:
                        if current_line:
                            current_length += 1
                        current_line.append(word)
                        current_length += len(word)
                
                if current_line:
                    wrapped_lines.append(f"{indent_str}{' '.join(current_line)}")
        
        wrapped_lines.append('')
    
    return '\n'.join(wrapped_lines[:-1])

def get_targets_by_form(form_type: str) -> list[str]:
    return [target for target, forms in TARGETS.items() if form_type in forms["forms"]]
