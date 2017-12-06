import os

plants_folder = 'static/plants/'
plant_folders = [name for name in os.listdir(plants_folder) if os.path.isdir((plants_folder + name))]
for folder in plant_folders:
    os.removedirs((plants_folder + folder))

os.remove('plants.db')