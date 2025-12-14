import asyncio
import logging
from aiogram import Bot, Dispatcher, types, F
from aiogram.filters import Command
from aiogram.types import InlineKeyboardMarkup, InlineKeyboardButton, FSInputFile
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import State, StatesGroup
from aiogram.fsm.storage.memory import MemoryStorage
import aiohttp
import os
from io import BytesIO
import base64

# Настройка логирования
logging.basicConfig(level=logging.INFO)

# ⚠️ ОБЯЗАТЕЛЬНО ЗАМЕНИТЕ НА СВОЙ ТОКЕН
BOT_TOKEN = "1739871606:AAExrRjrx6ikf1ZVOBHY0NpNdE6PU8UukIA"  
NANO_API_KEY = "104fd7bddfdad824400625c449141c16"

bot = Bot(token=BOT_TOKEN)
storage = MemoryStorage()
dp = Dispatcher(storage=storage)

# Состояния FSM
class FurnitureStates(StatesGroup):
    waiting_furniture_photo = State()
    waiting_furniture_prompt = State()
    waiting_room_photo = State()

# Предустановленные промты
FURNITURE_PROMPT = """Преобразуй это фото в профессиональную каталожную визуализацию мебели. Современный минималистичный интерьер, фотореализм, качество 4K. Сохрани композицию, размеры и пропорции шкафов и техники, выровняй перспективу и вертикали фасадов. Сделай ровные матовые фасады без дефектов, аккуратные стыки, реалисточные материалы и текстуры. Добавь теплый мягкий боковой свет слева, естественные мягкие тени. Нейтральные стены и потолок, теплый деревянный пол, чистое окружение без лишних предметов. Стиль — интерьерная рекламная фотосъёмка для каталога мебельной фабрики, широкий угол 24–35 мм, камера на уровне глаз, идеально сбалансированная экспозиция"""

ROOM_INTEGRATION_PROMPT = """Реалистично интегрируй мебель из предыдущего результата в интерьер этой комнаты. Сохрани освещение, перспективу и пропорции комнаты. Мебель должна идеально вписаться в пространство, с правильными тенями и отражениями. Фотореализм, 4K качество, профессиональная визуализация."""

async def generate_image(prompt: str, image_data: bytes = None) -> str:
    """Генерация изображения через Nano Banana Pro API"""
    url = "https://api.nanobanana.pro/v1/images/generations"
    
    data = {
        "prompt": prompt,
        "n": 1,
        "size": "1024x1024",
        "response_format": "url"
    }
    
    if image_data:
        # Конвертируем bytes в base64 для API
        image_b64 = base64.b64encode(image_data).decode('utf-8')
        data["image"] = f"data:image/jpeg;base64,{image_b64}"
    
    headers = {
        "Authorization": f"Bearer {NANO_API_KEY}",
        "Content-Type": "application/json"
    }
    
    async with aiohttp.ClientSession() as session:
        async with session.post(url, json=data, headers=headers, timeout=120) as response:
            if response.status == 200:
                result = await response.json()
                return result['data'][0]['url']
            else:
                logging.error(f"API Error: {await response.text()}")
                raise Exception("Ошибка генерации изображения")

def get_main_keyboard():
    keyboard = InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="🛋️ Генерация мебели", callback_data="generate_furniture")],
        [InlineKeyboardButton(text="📸 Улучшить качество", callback_data="improve_quality")],
        [InlineKeyboardButton(text="🏠 Добавить в комнату", callback_data="add_to_room")],
        [InlineKeyboardButton(text="🔄 Новый проект", callback_data="new_project")]
    ])
    return keyboard

def get_furniture_keyboard(furniture_url: str = None):
    keyboard = InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="✅ Готово для комнаты", callback_data="furniture_ready")],
        [InlineKeyboardButton(text="🔄 Перегенерировать", callback_data="regenerate")],
        [InlineKeyboardButton(text="✏️ Новый промт", callback_data="new_prompt")],
        [InlineKeyboardButton(text="🏠 Главное меню", callback_data="main_menu")]
    ])
    return keyboard

@dp.message(Command("start"))
async def start_handler(message: types.Message):
    await message.answer(
        "🎨 **Бот генерации мебели для каталогов**\n\n"
        "📋 **Как работать:**\n"
        "• Прикрепите эскиз шкафа/кухни\n"
        "• Нажмите 🛋️ **'Генерация мебели'**\n"
        "• Доработайте результат\n"
        "• Прикрепите фото комнаты → **'Добавить в комнату'**\n\n"
        "🎯 **Итог:** мебель в интерьере клиента!",
        reply_markup=get_main_keyboard(),
        parse_mode="Markdown"
    )

@dp.callback_query(F.data == "main_menu")
async def main_menu(callback: types.CallbackQuery):
    await callback.message.edit_text(
        "🎨 **Выберите действие:**",
        reply_markup=get_main_keyboard(),
        parse_mode="Markdown"
    )
    await callback.answer()

@dp.callback_query(F.data == "generate_furniture")
async def generate_furniture_start(callback: types.CallbackQuery, state: FSMContext):
    await state.set_state(FurnitureStates.waiting_furniture_photo)
    await callback.message.edit_text(
        "🛋️ **Прикрепите эскиз мебели**\n\n"
        "📸 Фото/рисунок шкафа, кухни со схемой\n"
        "📏 С размерами желательно",
        reply_markup=InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(text="❌ Отмена", callback_data="main_menu")]
        ]),
        parse_mode="Markdown"
    )
    await callback.answer()

@dp.message(FurnitureStates.waiting_furniture_photo)
async def process_furniture_photo(message: types.Message, state: FSMContext):
    if not message.photo:
        await message.answer("❌ Прикрепите фото эскиза!")
        return
    
    # Сохраняем фото
    photo = message.photo[-1]
    photo_bytes = await bot.download_file(photo.file_id)
    photo_bytes = photo_bytes.read()
    
    await state.update_data(furniture_photo=photo_bytes)
    await state.set_state(FurnitureStates.waiting_furniture_prompt)
    
    await message.answer(
        "✨ **Готово к генерации!**\n\n"
        "Выберите действие:",
        reply_markup=InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(text="🚀 Авто-каталог", callback_data="auto_catalog")],
            [InlineKeyboardButton(text="✏️ Свой промт", callback_data="custom_prompt")],
            [InlineKeyboardButton(text="❌ Отмена", callback_data="main_menu")]
        ]),
        parse_mode="Markdown"
    )

@dp.callback_query(F.data == "auto_catalog")
async def auto_catalog(callback: types.CallbackQuery, state: FSMContext):
    data = await state.get_data()
    photo_bytes = data['furniture_photo']
    
    await callback.message.edit_text("🎨 **Генерирую каталожную визуализацию...**")
    
    try:
        image_url = await generate_image(FURNITURE_PROMPT, photo_bytes)
        
        await state.update_data(
            current_image=image_url,
            current_prompt=FURNITURE_PROMPT
        )
        
        await callback.message.delete()
        await callback.message.answer_photo(
            photo=image_url,
            caption="✅ **Профессиональная визуализация готова!**\n\nЧто дальше?",
            reply_markup=get_furniture_keyboard(image_url),
            parse_mode="Markdown"
        )
        
    except Exception as e:
        await callback.message.answer(f"❌ Ошибка: {str(e)}")
    
    await callback.answer()

@dp.callback_query(F.data == "furniture_ready")
async def furniture_ready(callback: types.CallbackQuery, state: FSMContext):
    await state.set_state(FurnitureStates.waiting_room_photo)
    await callback.message.edit_text(
        "🏠 **Прикрепите фото комнаты клиента**\n\n"
        "📸 Реальный интерьер куда встанет мебель",
        reply_markup=InlineKeyboardMarkup(inline_keyboard=[
            [InlineKeyboardButton(text="❌ Отмена", callback_data="main_menu")]
        ]),
        parse_mode="Markdown"
    )
    await callback.answer()

@dp.message(FurnitureStates.waiting_room_photo)
async def process_room_photo(message: types.Message, state: FSMContext):
    if not message.photo:
        await message.answer("❌ Прикрепите фото комнаты!")
        return
        
    await message.answer("✅ Фото комнаты получено!")
    await state.set_state(None)
    
    data = await state.get_data()
    furniture_image = data.get('current_image')
    
    room_photo = message.photo[-1]
    room_bytes = await bot.download_file(room_photo.file_id)
    room_bytes = room_bytes.read()
    
    await message.answer("🎉 **Интегрирую мебель в интерьер...**")
    
    try:
        final_image_url = await generate_image(ROOM_INTEGRATION_PROMPT, room_bytes)
        
        await message.answer_photo(
            photo=final_image_url,
            caption="🎊 **ГОТОВО!** Мебель в интерьере клиента\n\n"
                   "🔄 Нажмите 'Новый проект' для следующего заказа",
            reply_markup=get_main_keyboard(),
            parse_mode="Markdown"
        )
        
        await state.clear()
        
    except Exception as e:
        await message.answer(f"❌ Ошибка интеграции: {str(e)}")

@dp.callback_query(F.data.in_({"regenerate", "improve_quality"}))
async def regenerate(callback: types.CallbackQuery, state: FSMContext):
    data = await state.get_data()
    photo_bytes = data['furniture_photo']
    prompt = data.get('current_prompt', FURNITURE_PROMPT)
    
    await callback.message.edit_caption("🔄 **Перегенерирую...**")
    
    try:
        new_image = await generate_image(prompt, photo_bytes)
        await state.update_data(current_image=new_image)
        
        await callback.message.edit_caption(
            "✅ **Новая версия готова!**\n\nЧто дальше?",
            reply_markup=get_furniture_keyboard(new_image),
            parse_mode="Markdown"
        )
    except Exception as e:
        await callback.message.answer(f"❌ Ошибка: {str(e)}")
    
    await callback.answer()

@dp.callback_query(F.data == "new_project")
async def new_project(callback: types.CallbackQuery, state: FSMContext):
    await state.clear()
    await main_menu(callback)

async def main():
    print("🚀 Бот запущен!")
    await dp.start_polling(bot)

if __name__ == "__main__":
    asyncio.run(main())
