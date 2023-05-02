#!/usr/bin/php
<?php

$handle = fopen('/dev/tty', 'w');
fwrite($handle, 'Direcotry: ');
$root = readline();

class tree
{
    private static string $path = '';
    private static int    $level;
    private static bool   $joliet;
    private static int    $depth;
    private static int    $number;
    private static int    $extent_dir;
    private static int    $extent_reg;
    private static int    $path_table;
    private static int    $root_size;
    private static int    $root_time;

    private static $handle = null;

    private string $name;
    private string $fullpath;
    private int    $extent;
    private int    $size;
    private int    $time;
    private int    $directory;
    private ?tree  $parent;
    private array  $children;
    private array  $offset;

    private function process(int $max_depth, int $depth)
    {
        if(self::$number == 0xFFFF)
        {
            return;
        }
        if($depth == $max_depth)
        {
            if($this->directory)
            {
                $this->size = 68;
                foreach($this->children as $child)
                {
                    $child->parent = $max_depth == 1 ? null : $this;
                    $length = (strlen($child->name) | 1) + 33 + ($child->directory ? 0 : 2);
                    for($i = intdiv($child->size - 2048, 0x100000000 - 2048); $i >= 0; $i--)
                    {
                        if(($this->size & 2047) + $length > 2048)
                        {
                            $this->size = ($this->size | 2047) + 1;
                        }
                        $child->offset[] = $this->size;
                        $this->size += $length;
                    }
                }
                $this->directory = ++self::$number;
                $this->extent = self::$extent_dir;
                self::$extent_dir += (($this->size - 1) >> 11) + 1;
                if($depth > 1)
                {
                    self::$path_table += ((strlen($this->name) + 1) & ~1) + 8;
                }
                else
                {
                    $this->parent = null;
                    self::$root_size = $this->size;
                    self::$root_time = $this->time;
                }
            }
            else
            {
                $this->extent = self::$extent_reg;
                self::$extent_reg += (($this->size - 1) >> 11) + 1;
            }
        }
        else
        {
            foreach($this->children as $child)
            {
                $child->process($max_depth, $depth + 1);
            }
        }
        return;
    }

    private function output_self()
    {
        $extent = 16 + 2 + (((self::$path_table - 1) >> 11) + 1) * 2 + $this->extent;
        $length = $this->size;
        $length = (($length - 1) | 2047) + 1;
        $date = new DateTime();
        $date->setTimestamp($this->time);
        echo "\42";
        echo "\0";
        echo pack('VN', $extent, $extent);
        echo pack('VN', $length, $length);
        echo pack('CCCCCCC', intval($date->format('Y')) - 1900, intval($date->format('n')), intval($date->format('j')), intval($date->format('G')), intval($date->format('i')), intval($date->format('s')), 0); # ~
        echo "\2";
        echo "\0";
        echo "\0";
        echo "\1\0\0\1";
        echo "\1";
        echo "\0";
        return;
    }

    private function output_parent()
    {
        $parent = $this->parent;
        $extent = 16 + 2 + (((self::$path_table - 1) >> 11) + 1) * 2 + (is_null($parent) ? 0 : $parent->extent);
        $length = is_null($parent) ? self::$root_size : $parent->size;
        $length = (($length - 1) | 2047) + 1;
        $date = new DateTime();
        $date->setTimestamp(is_null($parent) ? self::$root_time : $parent->time);
        echo "\42";
        echo "\0";
        echo pack('VN', $extent, $extent);
        echo pack('VN', $length, $length);
        echo pack('CCCCCCC', intval($date->format('Y')) - 1900, intval($date->format('n')), intval($date->format('j')), intval($date->format('G')), intval($date->format('i')), intval($date->format('s')), 0); # ~
        echo "\2";
        echo "\0";
        echo "\0";
        echo "\1\0\0\1";
        echo "\1";
        echo "\1";
        return;
    }

    private function output(int $max_depth, int $depth, bool $regular)
    {
        if($depth == $max_depth)
        {
            if($this->directory && $regular == false)
            {
                $this->output_self();
                $this->output_parent();
                $date = new DateTime();
                for($i = 0; $i < count($this->children); $i++)
                {
                    $extent = 16 + 2 + (((self::$path_table - 1) >> 11) + 1) * 2 + ($this->children[$i]->directory ? 0 : self::$extent_dir) + $this->children[$i]->extent;
                    $date->setTimestamp($this->children[$i]->time);
                    $size = $this->children[$i]->size;
                    for($j = 0; $j < count($this->children[$i]->offset); $j++)
                    {
                        $record = $j == count($this->children[$i]->offset) - 1 ? $i == count($this->children) - 1 ? $this->size - $this->children[$i]->offset[$j] : $this->children[$i + 1]->offset[0] - $this->children[$i]->offset[$j] : $this->children[$i]->offset[$j + 1] - $this->children[$i]->offset[$j];
                        $length = $j == count($this->children[$i]->offset) - 1 ? $size : 0xFFFFF800;
                        # ???
                        if($this->children[$i]->directory)
                        {
                            $length = (($length - 1) | 2047) + 1;
                        }
                        # ???
                        echo pack('C', (strlen($this->children[$i]->name) | 1) + ($this->children[$i]->directory ? 0 : 2) + 33);
                        echo "\0";
                        echo pack('VN', $extent, $extent);
                        echo pack('VN', $length, $length);
                        echo pack('CCCCCCC', intval($date->format('Y')) - 1900, intval($date->format('n')), intval($date->format('j')), intval($date->format('G')), intval($date->format('i')), intval($date->format('s')), 0); # ~
                        echo pack('C', ($this->children[$i]->directory ? 2 : 0) | ($j == count($this->children[$i]->offset) - 1 ? 0 : 128));
                        echo "\0";
                        echo "\0";
                        echo "\1\0\0\1";
                        echo pack('C', strlen($this->children[$i]->name) + ($this->children[$i]->directory ? 0 : 2));
                        echo $this->children[$i]->name;
                        if(!$this->children[$i]->directory)
                        {
                            echo ';1';
                        }
                        for($k = strlen($this->children[$i]->name) + ($this->children[$i]->directory ? 0 : 2) + 33; $k < $record; $k++)
                        {
                            echo "\0";
                        }
                        $extent += 0x1FFFFF;
                        $size -= 0xFFFFF800;
                    }
                }
                for($i = $this->size & 2047; $i > 0 && $i < 2048; $i++)
                {
                    echo "\0";
                }
            }
            elseif(!$this->directory && $regular == true)
            {
                $handle = fopen($this->fullpath, 'rb');
                while(!feof($handle))
                {
                    $s = fread($handle, 2048);
                    echo $s;
                }
                fclose($handle);
                for($i = $this->size & 2047; $i > 0 && $i < 2048; $i++)
                {
                    echo "\0";
                }
            }
        }
        else
        {
            foreach($this->children as $child)
            {
                $child->output($max_depth, $depth + 1, $regular);
            }
        }
        return;
    }

    private function print_path_table_internal(int $max_depth, int $depth, bool $big_endian)
    {
        if($depth == $max_depth)
        {
            $length = $depth == 1 ? 1 : strlen($this->name);
            echo pack('C', $length);
            echo "\0";
            echo pack($big_endian ? 'N' : 'V', 16 + 2 + (((self::$path_table - 1) >> 11) + 1) * 2 + $this->extent);
            echo pack($big_endian ? 'n' : 'v', is_null($this->parent) ? 1 : $this->parent->directory);
            echo $this->name;
            if($length & 1)
            {
                echo "\0";
            }
            if($depth == 1)
            {
                echo "\0";
            }
        }
        else
        {
            foreach($this->children as $child)
            {
                if($child->directory)
                {
                    $child->print_path_table_internal($max_depth, $depth + 1, $big_endian);
                }
            }
        }
    }

    private function print_path_table(bool $big_endian)
    {
        for($i = 1; $i <= self::$depth; $i++)
        {
            $this->print_path_table_internal($i, 1, $big_endian);
        }
        for($i = self::$path_table & 2047; $i > 0 && $i < 2048; $i++)
        {
            echo "\0";
        }
        return;
    }

    public function __construct(string $name, int $depth = 1)
    {
        if(self::$path == '')
        {
            if(substr($name, -1) == DIRECTORY_SEPARATOR)
            {
                self::$path = substr($name, 0, -1);
            }
            else
            {
                self::$path = $name;
            }
            $name  = '';
            $depth = 1;
            self::$level      = 1;
            self::$joliet     = false;
            self::$depth      = 1;
            self::$number     = 0;
            self::$extent_dir = 0;
            self::$extent_reg = 0;
            self::$path_table = 10;
            if(is_null(self::$handle))
            {
                self::$handle = fopen('/dev/tty', 'w');
            }
        }
        $this->name = $name;
        $this->fullpath = self::$path;
        $this->children = array();
        $this->offset   = array();
        $stat = stat(self::$path);
        $this->time = $stat['mtime'];
        switch($stat['mode'] & ~0xFFF)
        {
            case 0x4000:
                $this->size = 0;
                $this->directory = -1;
                if(self::$level < 2 && preg_match('/^[^.]{0,8}$/', $name) === 0)
                {
                    self::$level = 2;
                }
                if(self::$joliet == false && (strlen($name) > 30 || preg_match('/^[A-Z0-9_]*$/', $name) === 0))
                {
                    self::$joliet = true;
                }
                $path = self::$path;
                $handle = opendir(self::$path);
                while(($entry = readdir($handle)) !== false)
                {
                    if($entry != '.' && $entry != '..')
                    {
                        if(self::$depth <= $depth)
                        {
                            self::$depth = $depth + 1;
                        }
                        self::$path = $path . DIRECTORY_SEPARATOR . $entry;
                        $this->children[] = new tree($entry, $depth + 1);
                    }
                }
                closedir($handle);
                usort($this->children, function($a, $b)
                {
                    return strcmp($a->name, $b->name);
                });
                break;
            case 0x8000:
                if(strpos($name, '.') === false)
                {
                    $this->name .= '.';
                }
                $this->size = $stat['size'];
                $this->directory = 0;
                if(self::$level < 3 && $stat['size'] > 0xFFFFFFFF)
                {
                    self::$level = 3;
                }
                if(self::$level < 2 && preg_match('/^[^.]{0,8}(\.[^.]{0,3})?$/', $name) === 0)
                {
                    self::$level = 2;
                }
                if(self::$joliet == false && (strlen($name) > 30 + (strpos($name, '.') !== false ? 1 : 0) || preg_match('/^[A-Z0-9_]*(\.[A-Z0-9_]*)?$/', $name) === 0))
                {
                    self::$joliet = true;
                }
                break;
            default:
                throw new Exception('Unsupported file type: ' . self::$path);
        }
        if($name == '')
        {
            self::$path = '';
            fwrite(self::$handle, 'Level ' . self::$level . (self::$joliet ? ' + Joliet' : '') . "\n");
            fwrite(self::$handle, 'Directory depth: ' . self::$depth . "\n");
            for($i = 1; $i <= self::$depth; $i++)
            {
                $this->process($i, 1);
            }
        }
        return;
    }

    public function write()
    {
        for($i = 0; $i < 0x8000; $i++)
        {
            echo "\0";
        }

        # Primary Volume Descriptor
        echo "\1CD001\1\0";

        # System Identifier
        fwrite(self::$handle, 'System Identifier: ');
        $s = readline();
        echo $s;
        for($i = strlen($s); $i < 32; $i++)
        {
            echo ' ';
        }

        # Volume Identifier
        fwrite(self::$handle, 'Volume Identifier: ');
        $s = readline();
        echo $s;
        for($i = strlen($s); $i < 32; $i++)
        {
            echo ' ';
        }

        # Unused Field
        for($i = 0; $i < 8; $i++)
        {
            echo "\0";
        }

        # Volume Space Size
        $i = (((self::$path_table - 1) >> 11) + 1) * 2 + self::$extent_dir + self::$extent_reg + 16 + 2;
        echo pack('VN', $i, $i);

        # Unused Field
        for($i = 0; $i < 32; $i++)
        {
            echo "\0";
        }

        # Volume Set Size
        echo "\1\0\0\1";

        # Volume Sequence Number
        echo "\1\0\0\1";

        # Logical Block Size
        echo "\0\10\10\0";

        # Path Table Size
        echo pack('VN', self::$path_table, self::$path_table);

        # Location of Occurrence of Type L Path Table
        echo pack('V', 18);

        # Location of Optional Occurrence of Type L Path Table
        echo "\0\0\0\0";

        # Location of Occurrence of Type M Path Table
        echo pack('N', 18 + ((self::$path_table - 1) >> 11) + 1);

        # Location of Optional Occurrence of Type M Path Table
        echo "\0\0\0\0";

        # Directory Record for Root Directory
        $this->output_self();

        # Volume Set Identifier
        fwrite(self::$handle, 'Volume Set Identifier: ');
        $s = readline();
        echo $s;
        for($i = strlen($s); $i < 128; $i++)
        {
            echo ' ';
        }

        # Publisher Identifier
        for($i = 0; $i < 128; $i++)
        {
            echo ' ';
        }

        # Data Preparer Identifier
        for($i = 0; $i < 128; $i++)
        {
            echo ' ';
        }

        # Application Identifier
        for($i = 0; $i < 128; $i++)
        {
            echo ' ';
        }

        # Copyright File Identifier
        for($i = 0; $i < 37; $i++)
        {
            echo ' ';
        }

        # Abstract File Identifier
        for($i = 0; $i < 37; $i++)
        {
            echo ' ';
        }

        # Bibliographic File Identifier
        for($i = 0; $i < 37; $i++)
        {
            echo ' ';
        }

        # Volume Creation Date and Time
        # Volume Modification Date and Time
        # Volume Expiration Date and Time
        # Volume Effective Date and Time
        fwrite(self::$handle, "Volume Date and Time (2001-02-23T13:34:56+05:45): ");
        $date = new DateTime(readline());
        $s = $date->format('YmdHis') . '00' . pack('C', intdiv(intval($date->format('Z')), 900) + 48);
        $s = date_format($date, 'YmdHis') . '00' . pack('C', intdiv(intval(date_format($date, 'Z')), 900) + 48);
        echo $s, $s, "0000000000000000\0", "0000000000000000\0";

        # File Structure Version
        echo "\1";

        # Reserved for future standardization
        echo "\0";

        for($i = 0; $i < 512; ++$i)
        {
            echo ' ';
        }

        # Reserved for future standardization
        for($i = 0; $i < 653; ++$i)
        {
            echo "\0";
        }

        # Volume Descriptor Set Terminator
        echo "\377CD001\1";
        for($i = 0; $i < 2041; ++$i)
        {
            echo "\0";
        }

        $this->print_path_table(false);
        $this->print_path_table(true);

        for($i = 1; $i <= self::$depth; $i++)
        {
            $this->output($i, 1, false);
        }

        for($i = 1; $i <= self::$depth; $i++)
        {
            $this->output($i, 1, true);
        }

        return;
    }

    public function dump_tree(int $indent = 0)
    {
        if(!$indent)
        {
            fwrite(self::$handle, "Directory tree:\n");
        }
        foreach($this->children as $child)
        {
            // printf('% 4d ', $child->directory);
            fwrite(self::$handle, str_repeat(' ', $indent) . $child->name . "\n");
            if($child->directory)
            {
                $child->dump_tree($indent + 1);
            }
        }
        return;
    }
}

$tree = new tree($root);
$tree->dump_tree();
$tree->write();
