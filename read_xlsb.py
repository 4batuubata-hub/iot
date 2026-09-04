import pandas as pd

file_path = 'DATA_INPUT_OEE_50-BIG_PART_2026_BACKUP - 2.xlsb'

try:
    xlsb = pd.ExcelFile(file_path, engine='pyxlsb')
    print("Sheets available:", xlsb.sheet_names)
    
    for sheet in xlsb.sheet_names[:3]:
        print(f"\n--- Sheet: {sheet} ---")
        df = pd.read_excel(xlsb, sheet_name=sheet).head(10)
        print(df.to_string())
except Exception as e:
    print("Error reading xlsb:", e)
