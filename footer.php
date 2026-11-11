<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Bless Mall Footer Demo</title>
    <!-- Google Fonts & Font Awesome for Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&family=Playfair+Display:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: #faf7f0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        /* dummy page content to show footer at bottom */
        .demo-content {
            flex: 1;
            text-align: center;
            padding: 4rem 2rem;
            background: linear-gradient(145deg, #fef9e6 0%, #fff3e0 100%);
        }
        .demo-content h1 {
            font-family: 'Playfair Display', serif;
            color: #0B6E3D;
            margin-bottom: 1rem;
        }
        .kente-strip {
            width: 100%;
            height: 12px;
            background: repeating-linear-gradient(90deg, 
                #D32F2F 0px, #D32F2F 25px,
                #FFC107 25px, #FFC107 50px,
                #1B5E20 50px, #1B5E20 75px,
                #FF8F00 75px, #FF8F00 100px,
                #FBC02D 100px, #FBC02D 125px,
                #B71C1C 125px, #B71C1C 150px);
            background-size: 150px 12px;
        }
        /* subtle hover effects for footer links */
        footer a {
            transition: all 0.2s ease;
        }
        footer a:hover {
            opacity: 1 !important;
            color: #FFD966 !important;
            transform: translateX(2px);
        }
        .payment-badge {
            background: rgba(0,0,0,0.2);
            backdrop-filter: blur(2px);
            border-radius: 40px;
            padding: 6px 16px;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .footer-icon-box {
            transition: transform 0.2s ease;
        }
        .footer-icon-box:hover {
            transform: translateY(-3px);
        }
        @media (max-width: 680px) {
            footer div[style*="grid-template-columns"] {
                gap: 1.8rem;
            }
            .footer-bottom-row {
                flex-direction: column;
                text-align: center;
                gap: 0.8rem;
            }
        }
    </style>
</head>
<body>

<div class="demo-content">
    <h1>🇬🇭 Bless Mall – Authentic Chinese Products in Ghana</h1>
    <p style="max-width: 600px; margin: 1rem auto; color: #2c3e2f;">Experience the blend of Ghanaian warmth and quality imports. Scroll down to explore our enhanced cultural footer.</p>
    <i class="fas fa-arrow-down" style="font-size: 2rem; color: #0B6E3D; margin-top: 2rem; opacity: 0.7;"></i>
</div>

<!-- ===================== UPDATED FOOTER ===================== -->
<footer style="background: #0B6E3D; color: #F9F9F9; margin-top: 0; box-shadow: 0 -8px 20px rgba(0,0,0,0.08);">
    
    <!-- Enhanced Kente Strip with animation + wave effect -->
    <div class="kente-strip" style="opacity: 0.95; box-shadow: inset 0 1px 3px rgba(0,0,0,0.2);"></div>
    
    <div style="max-width: 1400px; margin: 0 auto; padding: 3rem 2rem 1.8rem;">
        
        <!-- MAIN GRID - Updated layout: extra column for cultural & value props -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 2.2rem 2rem;">
            
            <!-- Column 1: Brand + Adinkra Duafe (Beauty & Quality) with enhanced description -->
            <div>
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 1.2rem;">
                    <!-- Duafe (wooden comb) refined SVG -->
                    <svg width="42" height="42" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M40 10 L45 22 L58 22 L48 30 L52 44 L40 36 L28 44 L32 30 L22 22 L35 22 L40 10Z" fill="#D4AF37" stroke="#FDF5E6" stroke-width="1.5"/>
                        <circle cx="40" cy="18" r="3" fill="#FDF5E6"/>
                        <path d="M40 36 L40 70" stroke="#D4AF37" stroke-width="3.5" stroke-linecap="round"/>
                        <path d="M34 64 L40 70 L46 64" stroke="#FFD966" stroke-width="2" fill="none"/>
                    </svg>
                    <h3 style="font-family: 'Playfair Display', serif; font-size: 1.7rem; letter-spacing: -0.2px; font-weight: 600;">Bless Mall</h3>
                </div>
                <p style="font-size: 0.9rem; line-height: 1.55; opacity: 0.92; margin-bottom: 1.2rem;">
                    Your bridge between China and Ghana — authentic electronics, fashion, home essentials, and more. 
                    <strong>Trusted by thousands of Ghanaian families.</strong>
                </p>
                <!-- Social icons with brand colors -->
                <div style="margin-top: 0.8rem; display: flex; gap: 1.2rem;">
                    <a href="#" aria-label="Facebook" style="color: #D4AF37; font-size: 1.4rem; background: rgba(255,255,255,0.1); width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; transition: all 0.2s;"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" aria-label="Instagram" style="color: #D4AF37; font-size: 1.4rem; background: rgba(255,255,255,0.1); width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%;"><i class="fab fa-instagram"></i></a>
                    <a href="#" aria-label="WhatsApp" style="color: #D4AF37; font-size: 1.4rem; background: rgba(255,255,255,0.1); width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%;"><i class="fab fa-whatsapp"></i></a>
                    <a href="#" aria-label="TikTok" style="color: #D4AF37; font-size: 1.4rem; background: rgba(255,255,255,0.1); width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%;"><i class="fab fa-tiktok"></i></a>
                </div>
            </div>
            
            <!-- Column 2: Quick Links with improved icons & new added pages -->
            <div>
                <h4 style="font-family: 'Playfair Display', serif; margin-bottom: 1.2rem; border-left: 3px solid #D4AF37; padding-left: 14px; font-size: 1.25rem;">Quick Links</h4>
                <ul style="list-style: none; padding: 0; display: flex; flex-direction: column; gap: 0.7rem;">
                    <li><a href="about.php" style="color: #fff; text-decoration: none; opacity: 0.88; display: flex; align-items: center; gap: 8px;"><i class="fas fa-store" style="font-size: 0.8rem; color: #D4AF37; width: 20px;"></i> About Bless Mall</a></li>
                    <li><a href="shop.php" style="color: #fff; text-decoration: none; opacity: 0.88; display: flex; align-items: center; gap: 8px;"><i class="fas fa-tag" style="font-size: 0.8rem; color: #D4AF37; width: 20px;"></i> All Products</a></li>
                    <li><a href="express.php" style="color: #fff; text-decoration: none; opacity: 0.88; display: flex; align-items: center; gap: 8px;"><i class="fas fa-rocket" style="font-size: 0.8rem; color: #D4AF37;"></i> Express Delivery (Same-day)</a></li>
                    <li><a href="offers.php" style="color: #fff; text-decoration: none; opacity: 0.88; display: flex; align-items: center; gap: 8px;"><i class="fas fa-percent" style="font-size: 0.8rem; color: #D4AF37;"></i> Market Day Deals</a></li>
                    <li><a href="wholesale.php" style="color: #fff; text-decoration: none; opacity: 0.88; display: flex; align-items: center; gap: 8px;"><i class="fas fa-boxes" style="font-size: 0.8rem; color: #D4AF37;"></i> Wholesale Portal</a></li>
                    <li><a href="contact.php" style="color: #fff; text-decoration: none; opacity: 0.88; display: flex; align-items: center; gap: 8px;"><i class="fas fa-headset" style="font-size: 0.8rem; color: #D4AF37;"></i> 24/7 Support</a></li>
                </ul>
            </div>
            
            <!-- Column 3: Enhanced Eban (Security) + Trust factors & Customer Care -->
            <div>
                <h4 style="font-family: 'Playfair Display', serif; margin-bottom: 1.2rem; display: flex; align-items: center; gap: 10px; font-size: 1.25rem;">
                    <!-- Eban Adinkra (fence of safety) refined -->
                    <svg width="26" height="26" viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect x="15" y="20" width="30" height="30" stroke="#D4AF37" stroke-width="2" fill="none" rx="2"/>
                        <path d="M30 10 L45 20 L15 20 L30 10Z" stroke="#D4AF37" stroke-width="2" fill="#D4AF37" fill-opacity="0.4"/>
                        <circle cx="30" cy="35" r="5" fill="#D4AF37" stroke="#FDF5E6" stroke-width="1"/>
                        <path d="M30 27 L30 31 M30 39 L30 43" stroke="#D4AF37" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <span>Secure & Trusted</span>
                </h4>
                <ul style="list-style: none; padding: 0; display: flex; flex-direction: column; gap: 0.7rem;">
                    <li><a href="privacy.php" style="color: #fff; text-decoration: none; opacity: 0.88;"><i class="fas fa-lock" style="width: 22px; color: #D4AF37;"></i> Privacy Policy</a></li>
                    <li><a href="returns.php" style="color: #fff; text-decoration: none; opacity: 0.88;"><i class="fas fa-undo-alt" style="width: 22px; color: #D4AF37;"></i> Return & Refund (7 days)</a></li>
                    <li><a href="terms.php" style="color: #fff; text-decoration: none; opacity: 0.88;"><i class="fas fa-file-contract" style="width: 22px; color: #D4AF37;"></i> Terms of Service</a></li>
                    <li><span style="display: flex; gap: 10px; align-items: center;"><i class="fas fa-shield-alt" style="color: #D4AF37; width: 22px;"></i> SSL Encryption + Momo Secure</span></li>
                </ul>
                <!-- Gye Nyame + Sankofa mini badge (cultural trust) -->
                <div style="margin-top: 1.5rem; background: rgba(0,0,0,0.2); border-radius: 60px; padding: 8px 12px; display: inline-flex; align-items: center; gap: 10px; backdrop-filter: blur(2px);">
                    <svg width="24" height="24" viewBox="0 0 40 40" fill="none">
                        <circle cx="20" cy="20" r="16" stroke="#D4AF37" stroke-width="1.5" fill="none"/>
                        <path d="M20 8 L24 18 L34 18 L26 25 L30 36 L20 28 L10 36 L14 25 L6 18 L16 18 L20 8Z" fill="#D4AF37"/>
                    </svg>
                    <span style="font-size: 0.8rem; font-weight: 500;">Gye Nyame – supremacy of quality</span>
                </div>
            </div>
            
            <!-- Column 4: Contact + Location & Operating hours (New) -->
            <div>
                <h4 style="font-family: 'Playfair Display', serif; margin-bottom: 1rem; border-left: 3px solid #D4AF37; padding-left: 14px; font-size: 1.25rem;">📍 Reach Us</h4>
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <p style="display: flex; gap: 12px; align-items: flex-start;"><i class="fas fa-map-pin" style="color: #D4AF37; margin-top: 4px;"></i> <span>Spintex Road, Bless Mall Complex, Accra – Opposite Papaye</span></p>
                    <p><i class="fas fa-phone-alt" style="color: #D4AF37; width: 26px;"></i> +233 20 289 7810</p>
                    <p><i class="fab fa-whatsapp" style="color: #25D366; width: 26px;"></i> <strong>WhatsApp Orders:</strong> +233 20 289 7810</p>
                    <p><i class="fas fa-envelope" style="color: #D4AF37; width: 26px;"></i> info@blessmallgh.com</p>
                    <p><i class="far fa-clock" style="color: #D4AF37; width: 26px;"></i> Mon–Sat: 9am – 7pm | Sun: 11am–5pm</p>
                </div>
                <!-- payment methods modernized -->
                <div style="margin-top: 1.4rem;">
                    <div class="payment-badge">
                        <i class="fab fa-cc-visa" style="font-size: 1.3rem;"></i>
                        <i class="fab fa-cc-mastercard" style="font-size: 1.3rem;"></i>
                        <i class="fas fa-mobile-alt" style="font-size: 1.2rem;"></i>
                        <span style="font-size: 0.75rem;">MTN MoMo | Vodafone Cash | AirtelTigo</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- NEW ROW: Adinkra Cultural Strip + Featured Values as a badge row -->
        <div style="margin: 2rem 0 1.2rem; display: flex; flex-wrap: wrap; justify-content: center; gap: 1rem; border-top: 1px dashed rgba(255,215,0,0.3); border-bottom: 1px dashed rgba(255,215,0,0.3); padding: 1rem 0;">
            <div style="display: flex; align-items: center; gap: 8px; background: rgba(255,193,7,0.12); padding: 6px 18px; border-radius: 40px;">
                <svg width="20" height="20" viewBox="0 0 30 30" fill="none"><path d="M15 3 L19 11 L27 12 L21 18 L23 26 L15 22 L7 26 L9 18 L3 12 L11 11 L15 3Z" fill="#FFD966"/></svg>
                <span style="font-size: 0.8rem;">Sankofa – learn from the past, build future</span>
            </div>
            <div style="display: flex; align-items: center; gap: 8px; background: rgba(255,193,7,0.12); padding: 6px 18px; border-radius: 40px;">
                <i class="fas fa-hand-sparkles"></i>
                <span style="font-size: 0.8rem;">100% Authentic Chinese imports</span>
            </div>
            <div style="display: flex; align-items: center; gap: 8px; background: rgba(255,193,7,0.12); padding: 6px 18px; border-radius: 40px;">
                <i class="fas fa-truck-fast"></i>
                <span style="font-size: 0.8rem;">Free delivery on orders < GH₵ 300+</span>
            </div>
        </div>
        
        <!-- Enhanced Footer Bottom with Kente motif underline and dynamic year -->
        <div style="border-top: 1px solid rgba(255,255,255,0.2); margin-top: 1.2rem; padding-top: 1.8rem; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 1rem;" class="footer-bottom-row">
            <p style="font-size: 0.85rem; opacity: 0.85; letter-spacing: 0.3px;">
                <i class="far fa-copyright"></i> <?php echo date('Y'); ?> Bless Mall — Bridging Ghana & China with Integrity. 
                <span style="display: inline-block; margin-left: 6px;">🇬🇭 🇨🇳</span>
            </p>
            <div style="display: flex; gap: 28px; flex-wrap: wrap;">
                <span class="footer-icon-box"><i class="fas fa-award"></i> Ghanaian-Owned</span>
                <span class="footer-icon-box"><i class="fas fa-medal"></i> Best Price Promise</span>
                <span class="footer-icon-box"><i class="fas fa-leaf"></i> Eco-Friendly Packaging</span>
            </div>
        </div>

        <!-- Small Cultural disclaimer & design footnote -->
        <div style="text-align: center; margin-top: 1.6rem; font-size: 0.7rem; opacity: 0.6; border-top: none; padding-top: 0.2rem;">
            <span>✺ Adinkra symbols: Duafe (beauty & quality) | Eban (safety) | Gye Nyame (supremacy of God) ✺</span>
        </div>
    </div>
</footer>

<!-- global cart sync improvement with localStorage support / omnipresent cart count listener -->
<script>
    (function advancedFooterCartSync() {
        // Helper to update any cart-related elements across the website (header if exists)
        function broadcastCartCount() {
            let storedCart = localStorage.getItem('blessCart');
            let count = 0;
            try {
                if(storedCart) {
                    let cart = JSON.parse(storedCart);
                    if(Array.isArray(cart)) {
                        count = cart.reduce((total, item) => total + (item.quantity || 1), 0);
                    } else if(cart && typeof cart === 'object' && cart.items) {
                        count = cart.items.reduce((total, item) => total + (item.quantity || 1), 0);
                    }
                }
            } catch(e) { console.warn("cart parse warn"); }
            // dispatch custom event for any listening components
            window.dispatchEvent(new CustomEvent('cartUpdated', { detail: { count: count } }));
            // also try to update header if exists
            let headerCartSpan = document.getElementById('cartCountDisplay');
            if(headerCartSpan) headerCartSpan.innerText = count;
        }
        
        // initial load
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', broadcastCartCount);
        } else {
            broadcastCartCount();
        }
        
        // listen for storage changes (if cart updated from another tab)
        window.addEventListener('storage', function(e) {
            if(e.key === 'blessCart') {
                broadcastCartCount();
            }
        });
        
        // Intercept any manual cart update events from site scripts (custom)
        window.addEventListener('cartUpdated', function(e) {
            if(e.detail && typeof e.detail.count !== 'undefined') {
                let headerCountElem = document.getElementById('cartCountDisplay');
                if(headerCountElem) headerCountElem.innerText = e.detail.count;
                // also sync localStorage if needed? just reflect
            } else {
                broadcastCartCount();
            }
        });
        
        // Add fake demo cart for showcase purposes? (optional - only for demonstration but no auto-add)
        console.log("Footer loaded: cart sync active | Bless Mall cultural footer v2");
    })();
</script>

<!-- small style enhancement for hover -->
<style>
    footer a, footer .footer-icon-box {
        transition: all 0.2s ease;
    }
    footer a:hover i, footer a:hover {
        color: #FFE18A !important;
        transform: translateX(2px);
    }
    .footer-icon-box:hover {
        color: #FFD966;
        cursor: default;
    }
    footer svg, footer i {
        transition: transform 0.2s;
    }
    footer a:hover svg {
        transform: scale(1.05);
    }
    .payment-badge i, .payment-badge span {
        filter: drop-shadow(0 1px 1px rgba(0,0,0,0.2));
    }
</style>

</body>
</html>