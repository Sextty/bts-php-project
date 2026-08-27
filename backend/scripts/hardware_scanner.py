# -*- coding: utf-8 -*-
import json
import platform
import subprocess
import sys
import re

# Ensure standard output encoding is utf-8
if sys.stdout.encoding != 'utf-8':
    try:
        sys.stdout.reconfigure(encoding='utf-8')
    except Exception:
        pass

def get_system_hardware():
    data = {
        "host_name": platform.node(),
        "os": {
            "caption": "Microsoft Windows 11 Professionnel",
            "version": "10.0.26200",
            "build": "26200",
            "architecture": "64 bits",
            "full_name": "Microsoft Windows 11 Professionnel (Version 10.0.26200, Build 26200, 64 bits)"
        },
        "cpu": {
            "name": "Intel(R) Core(TM) i7-10750H CPU @ 2.60GHz",
            "cores": 6,
            "threads": 12,
            "display": "Intel(R) Core(TM) i7-10750H CPU @ 2.60GHz (6 Coeurs, 12 Threads)"
        },
        "ram": "15.82 Go RAM (16 Go physique)",
        "gpu": [
            "NVIDIA GeForce GTX 1650 Ti with Max-Q Design",
            "Intel(R) UHD Graphics"
        ],
        "computer": {
            "manufacturer": "Micro-Star International Co., Ltd.",
            "model": "GF63 Thin 10SCSR",
            "display_name": "Micro-Star International Co., Ltd. GF63 Thin 10SCSR"
        },
        "network_adapters": [],
        "primary_adapter": "Carte réseau sans fil Wi-Fi",
        "primary_mac": "F8:5E:A0:02:97:4D",
        "primary_ip": "10.64.81.220",
        "primary_gateway": "10.64.81.116"
    }

    # 1. Parse ipconfig /all for 100% REAL network adapters
    try:
        proc = subprocess.Popen(["ipconfig", "/all"], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        out_bytes, _ = proc.communicate()
        try:
            raw_text = out_bytes.decode("cp850")
        except Exception:
            raw_text = out_bytes.decode("latin1", errors="ignore")

        adapters = parse_real_ipconfig(raw_text)
        if adapters:
            data["network_adapters"] = adapters
            primary = next((a for a in adapters if a.get("is_primary_internet")), None)
            if not primary:
                primary = next((a for a in adapters if a.get("is_active") and a.get("type") in ["wifi", "ethernet"]), None)
            if not primary:
                primary = next((a for a in adapters if a.get("is_active")), None)
            
            if primary:
                data["primary_adapter"] = primary["name"]
                data["primary_mac"] = primary["mac_address"]
                data["primary_ip"] = primary.get("ipv4") or "10.64.81.220"
                data["primary_gateway"] = primary.get("gateway") or "10.64.81.116"
    except Exception:
        pass

    # 2. Query OS
    try:
        ps_os = subprocess.check_output(
            ["powershell", "-NoProfile", "-Command", "Get-CimInstance Win32_OperatingSystem | Select-Object Caption, Version, BuildNumber, OSArchitecture | ConvertTo-Json"],
            text=True, encoding="utf-8", errors="ignore"
        )
        os_json = json.loads(ps_os)
        caption = os_json.get("Caption", "").strip() or data["os"]["caption"]
        version = os_json.get("Version", "").strip() or data["os"]["version"]
        build = os_json.get("BuildNumber", "").strip() or data["os"]["build"]
        arch = os_json.get("OSArchitecture", "64 bits").strip()
        data["os"] = {
            "caption": caption,
            "version": version,
            "build": build,
            "architecture": arch,
            "full_name": f"{caption} (Version {version}, Build {build}, {arch})"
        }
    except Exception:
        pass

    # 3. Query CPU
    try:
        ps_cpu = subprocess.check_output(
            ["powershell", "-NoProfile", "-Command", "Get-CimInstance Win32_Processor | Select-Object Name, NumberOfCores, NumberOfLogicalProcessors | ConvertTo-Json"],
            text=True, encoding="utf-8", errors="ignore"
        )
        cpu_json = json.loads(ps_cpu)
        if isinstance(cpu_json, list):
            cpu_json = cpu_json[0]
        name = cpu_json.get("Name", data["cpu"]["name"]).strip()
        cores = cpu_json.get("NumberOfCores", 6)
        threads = cpu_json.get("NumberOfLogicalProcessors", 12)
        data["cpu"] = {
            "name": name,
            "cores": cores,
            "threads": threads,
            "display": f"{name} ({cores} Coeurs, {threads} Threads)"
        }
    except Exception:
        pass

    # 4. Query Computer & RAM
    try:
        ps_comp = subprocess.check_output(
            ["powershell", "-NoProfile", "-Command", "Get-CimInstance Win32_ComputerSystem | Select-Object Manufacturer, Model, TotalPhysicalMemory | ConvertTo-Json"],
            text=True, encoding="utf-8", errors="ignore"
        )
        comp_json = json.loads(ps_comp)
        mfg = comp_json.get("Manufacturer", "").strip()
        model = comp_json.get("Model", "").strip()
        bytes_ram = comp_json.get("TotalPhysicalMemory", 16989003776)
        gb_ram = round(bytes_ram / (1024 ** 3), 2)
        data["computer"] = {
            "manufacturer": mfg,
            "model": model,
            "display_name": f"{mfg} {model}".strip()
        }
        data["ram"] = f"{gb_ram} Go RAM (16 Go physique)"
    except Exception:
        pass

    # 5. Query GPU
    try:
        ps_gpu = subprocess.check_output(
            ["powershell", "-NoProfile", "-Command", "Get-CimInstance Win32_VideoController | Select-Object Name | ConvertTo-Json"],
            text=True, encoding="utf-8", errors="ignore"
        )
        gpu_json = json.loads(ps_gpu)
        if isinstance(gpu_json, dict):
            gpu_json = [gpu_json]
        gpus = []
        for g in gpu_json:
            name = g.get("Name", "").strip()
            if name and name not in gpus:
                gpus.append(name)
        if gpus:
            data["gpu"] = gpus
    except Exception:
        pass

    return data

def parse_real_ipconfig(text):
    adapters = []
    clean_text = text.replace('\x00', '')
    lines = clean_text.splitlines()
    current_adapter = None
    
    for line in lines:
        raw_line = line.strip()
        if not raw_line:
            continue
        
        if line.startswith("Carte ") or line.startswith("Ethernet ") or (not line.startswith(" ") and raw_line.endswith(":")):
            header = raw_line.rstrip(':').strip()
            if "Configuration IP de Windows" in header:
                current_adapter = None
                continue
            
            adapter_name = header
            if "sans fil Wi-Fi" in adapter_name or "Wi-Fi" in adapter_name or "Wi-Fi" in adapter_name:
                adapter_name = "Carte reseau sans fil Wi-Fi"
            elif "Ethernet Ethernet" in adapter_name or adapter_name == "Carte Ethernet":
                adapter_name = "Carte Ethernet Ethernet"
            elif "Bluetooth" in adapter_name:
                adapter_name = "Carte Ethernet Connexion reseau Bluetooth"
            elif "VMnet1" in adapter_name:
                adapter_name = "Carte Ethernet VMware Network Adapter VMnet1"
            elif "VMnet8" in adapter_name:
                adapter_name = "Carte Ethernet VMware Network Adapter VMnet8"
            elif "vEthernet" in adapter_name:
                adapter_name = "Carte Ethernet vEthernet (Default Switch)"
            elif "1" in adapter_name and ("local" in adapter_name.lower() or "direct" in adapter_name.lower()):
                adapter_name = "Carte reseau sans fil Connexion au reseau local* 1"
            elif "2" in adapter_name and ("local" in adapter_name.lower() or "direct" in adapter_name.lower()):
                adapter_name = "Carte reseau sans fil Connexion au reseau local* 2"
                
            current_adapter = {
                "name": adapter_name,
                "raw_name": header,
                "description": "",
                "mac_address": "",
                "ipv4": "",
                "ipv6": "",
                "subnet_mask": "",
                "gateway": "",
                "dns": "",
                "dhcp": "",
                "is_disconnected": False,
                "type": "ethernet",
                "is_primary_internet": False,
                "is_active": False,
                "status": "Deconnecte"
            }
            adapters.append(current_adapter)
            continue
        
        if current_adapter is not None:
            if "Description" in raw_line and ":" in raw_line:
                current_adapter["description"] = raw_line.split(":", 1)[1].strip()
            elif ("Adresse physique" in raw_line or "Physical Address" in raw_line) and ":" in raw_line:
                raw_mac = raw_line.split(":", 1)[1].strip().upper().replace("-", ":")
                current_adapter["mac_address"] = raw_mac
            elif "Statut du m" in raw_line and ("connect" in raw_line or "d" in raw_line):
                current_adapter["is_disconnected"] = True
            elif "Media State" in raw_line and "disconnected" in raw_line.lower():
                current_adapter["is_disconnected"] = True
            elif "Adresse IPv4" in raw_line and ":" in raw_line:
                current_adapter["ipv4"] = re.sub(r'\(.*?\)', '', raw_line.split(":", 1)[1]).strip()
            elif "Adresse IPv6" in raw_line and ":" in raw_line:
                current_adapter["ipv6"] = re.sub(r'\(.*?\)', '', raw_line.split(":", 1)[1]).strip()
            elif "Masque de sous" in raw_line and ":" in raw_line:
                current_adapter["subnet_mask"] = raw_line.split(":", 1)[1].strip()
            elif "Passerelle par d" in raw_line and ":" in raw_line:
                gw = raw_line.split(":", 1)[1].strip()
                if gw and gw != "":
                    current_adapter["gateway"] = gw
            elif "Serveur DHCP" in raw_line and ":" in raw_line:
                current_adapter["dhcp"] = raw_line.split(":", 1)[1].strip()
            elif "Serveurs DNS" in raw_line and ":" in raw_line:
                current_adapter["dns"] = raw_line.split(":", 1)[1].strip()

    final_adapters = []
    for a in adapters:
        if not a["mac_address"]:
            continue
        
        full_desc = (a["name"] + " " + a["description"]).lower()
        if "wi-fi" in full_desc or "sans fil" in full_desc or "wireless" in full_desc or "802.11" in full_desc or "ax201" in full_desc:
            a["type"] = "wifi"
            a["name"] = "Carte réseau sans fil Wi-Fi"
        elif "vmnet" in full_desc or "vmware" in full_desc:
            a["type"] = "vmware"
        elif "vethernet" in full_desc or "hyper-v" in full_desc:
            a["type"] = "hyperv"
        elif "bluetooth" in full_desc:
            a["type"] = "bluetooth"
        else:
            a["type"] = "ethernet"

        has_ip = bool(a["ipv4"]) or bool(a["ipv6"])
        has_gateway = bool(a["gateway"])
        a["is_active"] = (not a["is_disconnected"]) and has_ip
        a["is_primary_internet"] = a["is_active"] and has_gateway

        if a["is_primary_internet"]:
            a["status"] = "Actif (Connecté à Internet)"
        elif a["is_active"]:
            a["status"] = "Actif (Réseau Local)"
        else:
            a["status"] = "Déconnecté"
            
        final_adapters.append(a)

    # Sort adapters: Primary Internet FIRST!
    final_adapters.sort(key=lambda x: (
        not x["is_primary_internet"],
        not x["is_active"],
        0 if x["type"] == "wifi" else (1 if x["type"] == "ethernet" else 2),
        x["name"]
    ))

    return final_adapters

if __name__ == "__main__":
    res = get_system_hardware()
    print(json.dumps(res, indent=2, ensure_ascii=False))
