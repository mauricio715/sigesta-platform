import sys
from pathlib import Path

# Permite importar config, main, services... desde la raíz de ai-service
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
