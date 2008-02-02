<?php
/* 	
	Open Media Collectors Database
	Copyright (C) 2001,2005 by Jason Pell

	This program is free software; you can redistribute it and/or
	modify it under the terms of the GNU General Public License
	as published by the Free Software Foundation; either version 2
	of the License, or (at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
	
	-- CHANGLOG --
		
	Version		Comments
	-------		--------
	0.81		initial 0.81 release
	0.81p7		Fix to remove debug info.
	0.81p8		Fix to parse audio_lang correctly.
*/
include_once("./functions/SitePlugin.class.inc");
include_once("./site/amazonutils.php");
		
class amazonuk extends SitePlugin
{
	function amazonuk($site_type)
	{
		parent::SitePlugin($site_type);
	}
	
	function queryListing($page_no, $items_per_page, $offset, $s_item_type, $search_vars_r)
	{
		if(strlen($search_vars_r['amazukasin'])>0)
		{
			$this->addListingRow(NULL, NULL, NULL, array('amazukasin'=>$search_vars_r['amazukasin']));
			return TRUE;
		}
		else
		{
			// Get the mapped AMAZON index type
			$index_type = ifempty($this->getConfigValue('item_type_to_index_map', $s_item_type), strtolower($s_item_type));
			
			$queryUrl = 'http://www.amazon.co.uk/exec/obidos/external-search?url='.rawurlencode('index='.$index_type).'&keyword='.urlencode($search_vars_r['title']).'&sz='.$items_per_page.'&pg='.$page_no;
			
			$pageBuffer = $this->fetchURI($queryUrl);
		}
		
		if(strlen($pageBuffer)>0)
		{
			$amazukasin = FALSE;
			
			// check for an exact match, but not if this is second page of listings or more
			if(!$this->isPreviousPage())
			{
				if (preg_match("/ASIN: <font>(\w{10})<\/font>/", $pageBuffer, $regs))
				{
					$amazukasin = trim($regs[1]);
				}
				else if (preg_match("/ASIN: (\w{10})/", strip_tags($pageBuffer), $regs))
				{
					$amazukasin = trim($regs[1]);
				}
				else if (preg_match ("/ISBN: ([^;]+);/", strip_tags($pageBuffer), $regs)) // for books, ASIN is the same as ISBN
				{
					$amazukasin = trim($regs[1]);
				} 
			}
			
			// exact match
			if($amazukasin!==FALSE)
			{
				// single record returned
				$this->addListingRow(NULL, NULL, NULL, array('amazukasin'=>$amazukasin));
				
				return TRUE;
			}
			else
			{
				$pageBuffer = preg_replace('/[\r\n]+/', ' ', $pageBuffer);
			
				//<td class="resultCount">Showing 1 - 12 of 144 Results</td>
				//if(preg_match("/All[\s]*([0-9]+)[\s]*results for/i", $pageBuffer, $regs))
				if(preg_match("/<td class=\"resultCount\">Showing [0-9]+[\s]*-[\s]*[0-9]+ of ([0-9,]+) Results<\/td>/i", $pageBuffer, $regs) || 
						preg_match("/<td class=\"resultCount\">Showing ([0-9]+) Result.<\/td>/i", $pageBuffer, $regs))
				{
					// store total count here.
					$this->setTotalCount($regs[1]);

					// 1 = img, 2 = href, 3 = title					
					if(preg_match_all("!<td class=\"imageColumn\"[^>]*>.*?".
									"<img src=\"([^\"]+)\"[^>]*>".
									".*?".
									"<a href=\"([^\"]+)\"[^>]*><span class=\"srTitle\">([^<]+)</span></a>!m", $pageBuffer, $matches))
					{
						for($i=0; $i<count($matches[0]); $i++)
						{
							//http://www.amazon.co.uk/First-Blood-David-Morrell/dp/0446364401/sr=1-1/qid=1157433908/ref=pd_bbs_1/104-6027822-1371911?ie=UTF8&s=books
							if(preg_match("!/dp/([^/]+)/!", $matches[2][$i], $regs))
							{
								$this->addListingRow($matches[3][$i], $matches[1][$i], NULL, array('amazukasin'=>$regs[1]));
							}
						}
					}
				}					
			}
			
			//default
			return TRUE;
		}
		else
		{
			return FALSE;
		}
	}
	
	function queryItem($search_attributes_r, $s_item_type)
	{
		$pageBuffer = $this->fetchURI("http://www.amazon.co.uk/exec/obidos/ASIN/".$search_attributes_r['amazukasin']);
		
		// no sense going any further here.
		if(strlen($pageBuffer)==0)
			return FALSE;
			
		$pageBuffer = preg_replace('/[\r\n]+/', ' ', $pageBuffer);
		$pageBuffer = preg_replace('/>[\s]*</', '><', $pageBuffer);
		
		//<b class="sans">The Open Door </b>
		if(preg_match("/<b class=\"sans\">([^<]+)<\/b>/s", $pageBuffer, $regs))
		{
			$title = $regs[1];
			
			if( ($sqidx = strpos($title, "["))!==FALSE)
			{
				$title = substr($title,0,$sqidx);
			}
			
			$this->addItemAttribute('title', $title);
		}
		
		//http://www.amazon.co.uk/gp/product/images/B000050YLT/ref=dp_image_text_0/026-9147519-9634865?ie=UTF8
		$imageBuffer = $this->fetchURI("http://www.amazon.co.uk/gp/product/images/".$search_attributes_r['amazukasin']);
		if($imageBuffer!==FALSE)
	    {
	    	//fetchImage("alt_image_0", "http://images.amazon.com/images/P/B0000640RX.01._SS400_SCLZZZZZZZ_.jpg" );
	        if(preg_match_all("!fetchImage\(\"[^\"]+\", \"([^\"]+)\"!", $imageBuffer, $regs))
	        {
	        	$this->addItemAttribute('imageurl', $regs[1]);
	        } //<img src="http://images.amazon.com/images/P/B000FMH8RG.01._SS500_SCLZZZZZZZ_V52187861_.jpg" id="prodImage" />
	        else if(preg_match_all("!<img src=\"([^\"]+)\" id=\"prodImage\" />!", $imageBuffer, $regs))
	        {
	        	$this->addItemAttribute('imageurl', $regs[1]);	
	        }
	    }

	    // <td class="listprice">£20.00 </td>
		//<td><b class="price">£14.00</b>
		if (preg_match("!<td class=\"listprice\">.?([0-9\.]+)[\s]*</td>!", $pageBuffer, $regs))
		{
			$this->addItemAttribute('listprice', $regs[1]);
		}
		
		if (preg_match("!<b class=\"price\">.?([0-9\.]+)[\s]*</b>!", $pageBuffer, $regs))
		{
			$this->addItemAttribute('price', $regs[1]);
		}
		
		if(preg_match("!<a href=\"http://www.amazon.co.uk/gp/product/product-description/".$search_attributes_r['amazukasin']."/[^>]*>See all Reviews</a>!", $pageBuffer, $regs))
		{
			$reviewPage = $this->fetchURI("http://www.amazon.co.uk/gp/product/product-description/".$search_attributes_r['amazukasin']."/reviews/");
			if(strlen($reviewPage)>0)
			{
				$reviews = parse_amazon_reviews($reviewPage);
				if(is_not_empty_array($reviews))
				{
					$this->addItemAttribute('blurb', $reviews);
				}	
			}
		}
		else
		{
			$reviews = parse_amazon_reviews($pageBuffer);
			if(is_not_empty_array($reviews))
			{
				$this->addItemAttribute('blurb', $reviews);
			}
		}
		
		//http://g-ec2.images-amazon.com/images/G/01/x-locale/common/customer-reviews/stars-4-0._V47081936_.gif 
		if(preg_match("!<li><b>Average Customer Review:</b>[\s]*<img src=\".*?/stars-([^\.]+).!i", $pageBuffer, $regs))
		{
			$this->addItemAttribute('amznrating', str_replace('-', '.', $regs[1])) ;
		}
				
		// Get the mapped AMAZON index type
		$index_type = ifempty($this->getConfigValue('item_type_to_index_map', $s_item_type), strtolower($s_item_type));
				
		switch($index_type)
		{
			case 'dvd-uk':
			case 'vhs-uk':
				$this->parse_amazon_video_data($search_attributes_r, $s_item_type, $pageBuffer);
				break;
			
			case 'video-games-uk':
				$this->parse_amazon_game_data($search_attributes_r, $pageBuffer);
				break;
				
			case 'books-uk':
				$this->parse_amazon_books_data($search_attributes_r, $pageBuffer);
				break;
				
			case 'music':
				$this->parse_amazon_music_data($search_attributes_r, $pageBuffer);
				break;
			
			default://Not much here, but what else can we do?
				break;
		}
		
		return TRUE;
	}
	
	function parse_amazon_game_data($search_attributes_r, $pageBuffer)
	{
		if(preg_match("!by <a href=\".*?field-keywords=[^\"]*\">([^<]*)</a>!i", $pageBuffer, $regs))
		{
			$this->addItemAttribute('gamepblshr', $regs[1]);
		}

		if (preg_match("!<b>Platform:</b>[^<]*<img src=\"([^\"]+)\"[^<]*>([^<]+)</div>!mi", $pageBuffer, $regs))
		{
			// Different combo's of windows, lets treat them all as windows.
			if(strpos($regs[2], "Windows")!==FALSE)
				$platform = "Windows";
			else
				$platform = trim($regs[2]);

				$this->addItemAttribute('gamesystem', $platform);
		}
		
		//ELSPA</a> Minimum Age:</b> 15 <br>
		// Rating extraction block - For more information see:
		//  http://www.amazon.co.uk/exec/obidos/tg/browse/-/502556/202-1345170-2851025/202-1345170-2851025
		if (preg_match("!ELSPA</a> Rating:[\s]*</b>([^<]*)<!i", $pageBuffer, $regs))
		{
			$this->addItemAttribute('elsparated', $regs[1].'+'); // the '+' is required
		}
		
		//<a href="/gp/help/customer/display.html/203-0071143-3853558?ie=UTF8&amp;nodeId=502556">PEGI</a> Rating: </b>Ages 16 and Over
		if(preg_match("!PEGI</a> Rating:[\s]*</b>([^<]*)<!i", $pageBuffer, $regs))
		{
			$this->addItemAttribute('pegirated', $regs[1]);
		}
		
		if(preg_match("/<h2>Product Feat.*?<ul[^<]*>(.+?)<\/ul>/msi", $pageBuffer, $featureblock))
		{
			if(preg_match_all("/<li>([^<]*)<\/li>/si", $featureblock[1], $matches))
			{
				for($i = 0; $i < count($matches[1]); $i++)
				{
					$matches[1][$i] = strip_tags($matches[1][$i]);
				}

				$this->addItemAttribute('features', implode("\n", $matches[1]));
			}
		}
		
		if (preg_match("!<li><b> Release Date:</b>([^<]*)</li>!si", $pageBuffer, $regs))
		{
			$timestamp = strtotime($regs[1]);
    		$date = date('d/m/Y', $timestamp);
    		$this->addItemAttribute('gamepbdate', $date);
		}
		
		// now parse game plot
		$start = strpos($pageBuffer, "<div class=\"bucket\" id=\"productDescription\">");
		if($start!==FALSE) 
			$start = strpos($pageBuffer, "<b class=\"h1\">Reviews</b>", $start);
		if($start!==FALSE)
			$start = strpos($pageBuffer, "<div class=\"content\">", $start);
		if($start!==FALSE)
			$start = strpos($pageBuffer, "<b>Manufacturer's Description</b>", $start);

		if($start!==FALSE)
		{
			$start += strlen("<b>Manufacturer's Description</b>");
			$end = strpos($pageBuffer, "</div>", $start);
			$productDescriptionBlock = substr($pageBuffer, $start, $end-$start);
			$this->addItemAttribute('game_plot', $productDescriptionBlock);
		}
	}
	
	function parse_amazon_music_data($search_attributes_r, $pageBuffer)
	{
		//<META name="description" content="Come on Over, Shania Twain, Mercury">
		if(preg_match("/<meta name=\"description\" content=\"([^\"]*)\"/i",$pageBuffer, $regs))
		{
			if(preg_match("/by (.*)/i", $regs[1], $regs2))
			{
				// the artist is the last part of the description.
				// Amazon.fr : Dangerous: Musique: Michael Jackson by Michael Jackson
				$this->addItemAttribute('artist', $regs2[1]);
			}
		}
		
		//<li><b>Label:</b> Columbia</li>
		//<b>Label:</b> <a HREF="/exec/obidos/search-handle-url/size=20&store-name=music&index=music&field-label=Mercury/026-5027435-0842841">Mercury</a><br>
		if(preg_match("!<b>Label:[\s]*</b>[\s]*([^<]+)</li>!i", $pageBuffer, $regs))
		{
			$this->addItemAttribute('musiclabel', $regs[1]);
		}
		
		//<B> Audio CD </B>
		//(November 18, 2002)<br>
		//<li><b>Audio CD</b>  (2 Oct 2006)</li>
		if(preg_match("!<b>[\s]*Audio CD[\s]*</b>.*\(([^\)]+)\)</li>!sUi", $pageBuffer, $regs))
		{
			$timestamp = strtotime($regs[1]);

			$this->addItemAttribute('release_dt', date('d/m/Y', $timestamp));
    		$this->addItemAttribute('year', date('Y', $timestamp));
		}
		
		//<li><b>Number of Discs:</b> 1</li>
		if(preg_match("!<b>Number of Discs:[\s]*</b>[\s]*([0-9]+)!", $pageBuffer, $regs))
		{
			$this->addItemAttribute('no_discs', $regs[1]);
		}
		
		//http://www.amazon.co.uk/dp/samples/B0000029LG/
		if(preg_match("!http://www.amazon.co.uk/.*/dp/samples/".$search_attributes_r['amazukasin']."/!", $pageBuffer, $regs))
		{
			$samplesPage = $this->fetchURI("http://www.amazon.co.uk/dp/samples/".$search_attributes_r['amazukasin']."/");
			if(strlen($samplesPage)>0)
			{
				$samplesPage = preg_replace('/[\r\n]+/', ' ', $samplesPage);
				$tracks = parse_music_tracks($samplesPage);
				$this->addItemAttribute('cdtrack', $tracks);
			}
		}
		else if(preg_match("!<div class=\"bucket\">[\s]*<b class=\"h1\">Track Listings</b>(.*?)</div>!", $pageBuffer, $regs) || 
				preg_match("!<div class=\"bucket\">[\s]*<b class=\"h1\">Listen to Samples</b>(.*?)</div>!", $pageBuffer, $regs))
		{
			$tracks = parse_music_tracks($regs[1]);
			$this->addItemAttribute('cdtrack', $tracks);
		}
	}
	
	function parse_amazon_books_data($search_attributes_r, $pageBuffer)
	{
		//<meta name="description" content="Amazon.co.uk: Rambo: First Blood: Books: David Morrell by David Morrell" />
		$start = strpos($pageBuffer, "<div class=\"buying\">");
		if($start!==FALSE)
			$start = strpos($pageBuffer, "<b class=\"sans\">");
		
		if($start!==FALSE)
		{
			$end = strpos($pageBuffer, "</div>", $start);
			$authorBlock = substr($pageBuffer, $start, $end-$start);
			
			if(preg_match_all("!<a href=\".*?field-author=[^\"]*\">([^<]*)</a>!i", $authorBlock, $regs))
			{
				$this->addItemAttribute('author', $regs[1]);
			}
		}
	
		if( ( $startIndex = strpos($pageBuffer, "<b class=\"h1\">Look for similar items by subject</b>") ) !== FALSE &&
				($endIndex = strpos($pageBuffer, "</form>", $startIndex) ) !== FALSE )
		{
			$subjectform = substr($pageBuffer, $startIndex, $endIndex-$startIndex);
			
			if(preg_match_all("!<input type=\"checkbox\" name=\"field\+keywords\" value=\"([^\"]+)\"!", $subjectform, $matches))
			{
				$this->addItemAttribute('genre', $matches[1]);
			}
		}
		
		//<li><b>ISBN-10:</b> 0261102389</li>
		//<li><b>ISBN-13:</b> 978-0261102385</li>

		if(preg_match("!<b>ISBN-10:</b>[\s]*([0-9]+)!", $pageBuffer, $regs))
		{
			$this->addItemAttribute('isbn', $regs[1]);
			$this->addItemAttribute('isbn10', $regs[1]);
		}
		
		if(preg_match("!<b>ISBN-13:</b>[\s]*([0-9\-]+)!", $pageBuffer, $regs))
		{
			$this->addItemAttribute('isbn13', $regs[1]);
		}

		//<li><b>Paperback:</b> 1500 pages</li>
		if(preg_match("/([0-9]+) pages/", $pageBuffer, $regs))
		{
			$this->addItemAttribute('nb_pages', $regs[1]);
		}
		
		//<li><b>Publisher:</b> HarperCollins; New Ed edition (1 Mar 1999)</li>
		if(preg_match("!<b>Publisher:</b>[\s]*([^;<]+);([^<]+)</li>!U", $pageBuffer, $regs)) 
		{
			$this->addItemAttribute('publisher', $regs[1]);
			
			if(preg_match("!\(([^\)]*[0-9]+)\)!", $regs[2], $regs2))
			{
				$timestamp = strtotime($regs2[1]);
	    		$date = date('d/m/Y', $timestamp);
	    		$this->addItemAttribute('pub_date', $date);
			}
		}
		else if(preg_match("!<b>Publisher:</b>[\s]*([^<]+)</li>!U", $pageBuffer, $regs)) 
		{
			if(preg_match("!([^\(]+)\(!", $regs[1], $regs2))
			{
				$this->addItemAttribute('publisher', $regs2[1]);
			}
			
			if(preg_match("!\(([^\)]*[0-9]+)\)!", $regs[1], $regs2))
			{
				$timestamp = strtotime($regs2[1]);
	    		$date = date('d/m/Y', $timestamp);
	    		$this->addItemAttribute('pub_date', $date);
			}
		}
	}
	
	function parse_amazon_video_data($search_attributes_r, $s_item_type, $pageBuffer)
	{
		//<b>Rambo - First Blood [1982]</b>
		// Need to escape any (, ), [, ], :, ., 
		//<b class="sans">Rambo: First Blood Part II [1985] (1985)</b>
		if(preg_match("/<b.*>".preg_quote($this->getItemAttribute('title'), "/")."[\s]*\[([0-9]*)\]/s", $pageBuffer, $regs))
		{
			$this->addItemAttribute('year', $regs[1]);
		}
		else if(preg_match("/<b.*>".preg_quote($this->getItemAttribute('title'), "/")."[\s]*\(([0-9]*)\)<\/b>/s", $pageBuffer, $regs))
		{
			$this->addItemAttribute('year', $regs[1]);
		}
	
		//<img src="http://g-images.amazon.com/images/G/02/uk-video/misc/15-rating-27x21.gif" width="27" alt="15" height="21" border="0" />
		if (preg_match("/<b>Classification:<\/b>[\s]*<img src=.*? alt=\"([^\"]+)\".*?>/mi", $pageBuffer, $regs))
		{
			$this->addItemAttribute('age_rating', $regs[1]);
		}
		
		$this->addItemAttribute('actors', parse_amazon_video_people("Actors", $pageBuffer));
		$this->addItemAttribute('director', parse_amazon_video_people("Directors", $pageBuffer));

		//<li><b>Studio:</b>  Momentum Pictures Home Ent</li>			
		if (preg_match("!<li><b>Studio:[\s]*</b>([^<]*)</li>!", $pageBuffer, $regs))
		{
			$this->addItemAttribute('studio', $regs[1]);
		}

		if (preg_match("!<li><b>DVD Release Date:[\s]*</b>([^<]*)</li>!", $pageBuffer, $regs))
		{
			// Get year only, for now.  In the future we may add ability to
			// convert date to local date format.
			if(preg_match("/([0-9]+)$/m", $regs[1], $regs2))
			{
				$this->addItemAttribute('dvd_rel_dt', $regs2[1]);
			}
		}
		
		if (preg_match("!<li><b>Number of discs:[\s]*</b>[\s]*([0-9]+)!", $pageBuffer, $regs))
		{
			$this->addItemAttribute('no_discs', $regs[1]);
		}
		
		if (preg_match("!<li><b>Aspect Ratio:[\s]*</b>[\s]*([0-9\.]+)!", $pageBuffer, $regs))
		{
			$this->addItemAttribute('ratio', $regs[1]);
		}
		
		if (preg_match("!<li><b>Run Time:[\s]*</b>[\s]*([0-9]+)!", $pageBuffer, $regs))
		{
			$this->addItemAttribute('run_time', $regs[1]);
		}
		
		// Region extraction block
		if (preg_match("!<li><b>Region:[\s]*</b>Region ([0-9]+)!", $pageBuffer, $regs))
		{
			$this->addItemAttribute('dvd_region', $regs[1]);
		}
		
		//<li><b>Format: </b>Anamorphic, PAL, Widescreen</li>
		if (preg_match("!<li><b>Format:[\s]*</b>([^<]*)</li>!", $pageBuffer, $regs))
		{
			if (preg_match("/NTSC/", $regs[1], $regs2))
				$this->addItemAttribute('vid_format', 'NTSC');
			else
				$this->addItemAttribute('vid_format', 'PAL');
				
			if (preg_match("/Anamorphic/", $regs[1], $regs2))
				$this->addItemAttribute('anamorphic', 'Y');
		}
		
		if (preg_match("!<li><b>DVD Features:[\s]*</b><ul>(.*?)</ul>!", $pageBuffer, $regs))
		{
			//Available Subtitles, Available Audio Tracks, Main Language, Available Audio Tracks, Sub Titles, Disc Format
			if(preg_match_all("!<li>(.*?)</li>!", $regs[1], $matches))
			{
				$dvd_extras = NULL;
				
				for($i=0; $i<count($matches[0]); $i++)
				{
					if(preg_match("!<li>(.*?):(.*?)</li>!", $matches[0][$i], $matches2))
					{
						if($matches2[1] == 'Available Subtitles' || $matches2[1] == 'Sub Titles')
						{
							$this->addItemAttribute('subtitles', trim_explode(",", $matches2[2]));
						}
						else if($matches2[1] == 'Available Audio Tracks')
						{
							$this->addItemAttribute('audio_lang', trim_explode(",", $matches2[2]));
						}
					}
					else
					{
						$dvd_extras[] = $item;
					}
				}
				
				if(is_array($dvd_extras))
				{
					$this->addItemAttribute('dvd_extras', implode("\n", $dvd_extras));
				}					
			}
		}

		if(preg_match("!http://amazon.imdb.com/title/tt([0-9]*)!", $pageBuffer, $regs))
		{
			$this->addItemAttribute('imdb_id', $regs[1]);
		}
		
		// Attempt to include data from IMDB if available - but only for DVD, VHS, etc
		// as IMDB does not work with BOOKS or CD's.
		if(is_numeric($this->getItemAttribute('imdb_id')))
		{
			$sitePlugin =& get_site_plugin_instance('imdb');
			if($sitePlugin !== FALSE)
			{
				if($sitePlugin->queryItem(array('imdb_id'=>$this->getItemAttribute('imdb_id')), $s_item_type))
				{
					// no mapping process is performed here, as no $s_item_type was provided.
					$itemData = $sitePlugin->getItemData();
					if(is_array($itemData))
	      			{
						// merge data in here.
						while(list($key,$value) = each($itemData))
						{
							if($key == 'actors')
								$this->replaceItemAttribute('actors', $value);
							else if($key == 'director')
								$this->replaceItemAttribute('director', $value);
							else if($key == 'year')
								$this->replaceItemAttribute('year', $value);
							else if($key == 'actors')
								$this->replaceItemAttribute('actors', $value);
							else if($key == 'genre')
								$this->replaceItemAttribute('genre', $value);
							else if($key == 'plot') //have to map from imdb to amazon attribute type.
								$this->addItemAttribute('blurb', $value);
							else if($key != 'age_rating' && $key != 'run_time')
								$this->addItemAttribute($key, $value);
						}
					}
				}
			}
		}
	}
}
?>
